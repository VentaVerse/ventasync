<?php

namespace Extensions\lazada\Services\Lazada;

use App\Support\PayoutStatus;
use Carbon\Carbon;
use Extensions\lazada\Models\LazadaOrder;
use Extensions\lazada\Models\LazadaSetting;

class LazadaPayoutSync
{
    public const PAYABLE_STATUSES = ['delivered', 'confirmed', 'completed'];

    private const WINDOW_DAYS = 179;

    private const PAGE_SIZE = 500;

    private const SALES_CREDIT = '13';

    private const MAX_PAGES_PER_WINDOW = 100;

    private const PATH = '/finance/transaction/details/get';

    private const MAX_OLD_CHECKS = 40;

    private const OLD_CHECK_TTL = 30 * 86400;

    public function __construct(private readonly LazadaClient $client)
    {
    }

    public static function attention(): array
    {
        return [
            'channel' => 'lazada',
            'table' => 'lazada_orders',
            'store_column' => 'lazada_setting_id',
            'id_column' => 'order_id',
            'payable' => self::PAYABLE_STATUSES,
            'order_route' => 'ext.lazada.orders.show',
            'order_param' => 'orderId',
            'order_key' => 'ref',
        ];
    }

    public function sync(LazadaSetting $store, int $days, ?callable $say = null, bool $recheck = false): array
    {
        $say ??= static function (string $line): void {};
        $out = ['ok' => true, 'message' => '', 'waiting' => 0, 'requests' => 0, 'paid' => 0, 'releasing' => 0, 'no_payout' => 0];

        $setting = $store->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (empty($creds['complete']) || ! $setting->region) {
            return ['ok' => false, 'message' => 'This store is not connected to Lazada.'] + $out;
        }

        $payable = LazadaOrder::query()->where('lazada_setting_id', $store->id)->whereIn('status', self::PAYABLE_STATUSES);

        $out['paid'] += $this->checkOldOrders($store, $setting, $creds, $days, $out['requests']);
        $out['no_payout'] = PayoutStatus::retireOlderThan($payable, $days);

        $waiting = (clone $payable)
            ->where('order_created_at', '>=', now()->subDays($days))
            ->when(! $recheck, fn ($w) => $w->where(fn ($q) => $q->whereNull('payout_status')->orWhere('payout_status', '!=', PayoutStatus::PAID)))
            ->get(['id', 'order_id', 'order_created_at', 'payout_status']);
        $out['waiting'] = $waiting->count();

        if ($waiting->isEmpty()) {
            $out['message'] = 'No delivered orders are waiting for a payout'
                . ($out['no_payout'] ? "; {$out['no_payout']} older than {$days} days marked No payout found." : '.');
            $say($out['message']);

            return $out;
        }

        $byOrderId = $waiting->keyBy(fn ($o) => (string) $o->order_id);
        $creditedOn = [];
        $start = Carbon::createFromTimestamp((int) $waiting->min(fn ($o) => $o->order_created_at?->getTimestamp() ?? time()))->subDay()->startOfDay();
        $end = now()->addDay()->startOfDay();

        for ($from = $start->copy(); $from->lt($end) && count($creditedOn) < $byOrderId->count(); $from->addDays(self::WINDOW_DAYS)) {
            $to = $from->copy()->addDays(self::WINDOW_DAYS - 1)->min($end);
            $offset = 0;
            $pages = 0;
            do {
                $lines = $this->creditsBetween($setting, $creds, $from, $to, $offset);
                $out['requests']++;
                if (is_string($lines)) {
                    $out['ok'] = false;
                    $out['message'] = 'Lazada did not list the sales credits from ' . $from->toDateString() . ': ' . $lines
                        . ". {$out['paid']} found before it are kept.";
                    break 2;
                }
                foreach ($lines as $line) {
                    $orderId = (string) ($line['order_no'] ?? '');
                    if ($orderId === '' || ! $byOrderId->has($orderId) || ($line['fee_name'] ?? '') !== 'Item Price Credit') {
                        continue;
                    }
                    $day = $this->day((string) ($line['transaction_date'] ?? ''));
                    if ($day !== null && (! isset($creditedOn[$orderId]) || $day->lt($creditedOn[$orderId]))) {
                        $creditedOn[$orderId] = $day;
                    }
                }
                $offset += self::PAGE_SIZE;
                $pages++;
            } while (count($lines) === self::PAGE_SIZE && $pages < self::MAX_PAGES_PER_WINDOW);
        }

        foreach ($waiting as $order) {
            $key = (string) $order->order_id;
            if (isset($creditedOn[$key])) {
                LazadaOrder::query()->whereKey($order->id)->update([
                    'payout_status' => PayoutStatus::PAID,
                    'paid_at' => $creditedOn[$key]->toDateTimeString(),
                ]);
                $out['paid']++;
            } elseif ($out['ok']) {
                LazadaOrder::query()->whereKey($order->id)->update(['payout_status' => PayoutStatus::RELEASING, 'paid_at' => null]);
                $out['releasing']++;
            }
        }

        if ($out['ok']) {
            $out['message'] = "Payouts: {$out['paid']} paid, {$out['releasing']} releasing, from {$out['requests']} finance requests"
                . ($out['no_payout'] ? "; {$out['no_payout']} older than {$days} days marked No payout found." : '.');
        }
        $say($out['message']);

        return $out;
    }

    private function checkOldOrders(LazadaSetting $store, object $setting, array $creds, int $days, int &$requests): int
    {
        $old = LazadaOrder::query()
            ->where('lazada_setting_id', $store->id)
            ->whereIn('status', self::PAYABLE_STATUSES)
            ->where('order_created_at', '<', now()->subDays($days))
            ->where(fn ($q) => $q->whereNull('payout_status')->orWhere('payout_status', '!=', PayoutStatus::PAID))
            ->orderByDesc('order_created_at')
            ->get(['id', 'order_id', 'order_created_at'])
            ->filter(fn ($o) => ! \Illuminate\Support\Facades\Cache::has('lazada:old-check:' . $o->id))
            ->take(self::MAX_OLD_CHECKS);

        $paid = 0;
        foreach ($old as $o) {
            \Illuminate\Support\Facades\Cache::put('lazada:old-check:' . $o->id, 1, self::OLD_CHECK_TTL);
            $start = Carbon::instance($o->order_created_at)->subDay()->startOfDay();
            $creditedOn = null;
            for ($w = 0; $w < 2 && $creditedOn === null; $w++) {
                $from = $start->copy()->addDays($w * self::WINDOW_DAYS);
                if ($from->isFuture()) {
                    break;
                }
                $lines = $this->linesForOrder($setting, $creds, (string) $o->order_id, $from, $from->copy()->addDays(self::WINDOW_DAYS - 1)->min(now()));
                $requests++;
                if (is_string($lines)) {
                    break;
                }
                foreach ($lines as $line) {
                    if (($line['fee_name'] ?? '') === 'Item Price Credit' && ($day = $this->day((string) ($line['transaction_date'] ?? '')))) {
                        $creditedOn = $creditedOn === null || $day->lt($creditedOn) ? $day : $creditedOn;
                    }
                }
            }
            if ($creditedOn !== null) {
                LazadaOrder::query()->whereKey($o->id)->update([
                    'payout_status' => PayoutStatus::PAID,
                    'paid_at' => $creditedOn->toDateTimeString(),
                ]);
                $paid++;
            }
        }

        return $paid;
    }

    private function linesForOrder(object $setting, array $creds, string $orderId, Carbon $from, Carbon $to): array|string
    {
        $params = [
            'app_key' => (string) $creds['app_key'],
            'sign_method' => 'sha256',
            'timestamp' => (string) round(microtime(true) * 1000),
            'access_token' => (string) $creds['access_token'],
            'start_time' => $from->toDateString(),
            'end_time' => $to->toDateString(),
            'trade_order_id' => $orderId,
            'limit' => (string) self::PAGE_SIZE,
            'offset' => '0',
        ];
        $params['sign'] = $this->client->sign(self::PATH, $params, (string) $creds['app_secret']);
        $result = $this->client->get((string) $setting->region, self::PATH, $params);

        $body = is_array($result['body'] ?? null) ? $result['body'] : [];
        if (empty($result['ok']) || (string) ($body['code'] ?? '0') !== '0') {
            return trim((string) ($body['message'] ?? '')) ?: ('HTTP ' . ($result['status'] ?? 0));
        }
        $data = $body['data'] ?? [];
        if (is_array($data) && ! array_is_list($data)) {
            $data = $data['data'] ?? $data['items'] ?? [];
        }

        return is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
    }

    private function creditsBetween(object $setting, array $creds, Carbon $from, Carbon $to, int $offset): array|string
    {
        $params = [
            'app_key' => (string) $creds['app_key'],
            'sign_method' => 'sha256',
            'timestamp' => (string) round(microtime(true) * 1000),
            'access_token' => (string) $creds['access_token'],
            'start_time' => $from->toDateString(),
            'end_time' => $to->toDateString(),
            'trans_type' => self::SALES_CREDIT,
            'limit' => (string) self::PAGE_SIZE,
            'offset' => (string) $offset,
        ];
        $params['sign'] = $this->client->sign(self::PATH, $params, (string) $creds['app_secret']);
        $result = $this->client->get((string) $setting->region, self::PATH, $params);

        $body = is_array($result['body'] ?? null) ? $result['body'] : [];
        if (empty($result['ok']) || (string) ($body['code'] ?? '0') !== '0') {
            return trim((string) ($body['message'] ?? '')) ?: ('HTTP ' . ($result['status'] ?? 0));
        }
        $data = $body['data'] ?? [];
        if (is_array($data) && ! array_is_list($data)) {
            $data = $data['data'] ?? $data['items'] ?? [];
        }

        return is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
    }

    private function day(string $value): ?Carbon
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            return Carbon::createFromFormat('d M Y', $value)->startOfDay();
        } catch (\Throwable) {
            try {
                return Carbon::parse($value)->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        }
    }
}
