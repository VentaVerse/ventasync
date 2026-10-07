<?php

namespace Extensions\shopee\Services\Shopee;

use App\Support\PayoutStatus;
use Extensions\shopee\Models\ShopeeApiLog;
use Extensions\shopee\Models\ShopeeOrder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Extensions\shopee\Models\ShopeeSetting;

class ShopeePayoutSync
{
    private const WINDOW_SECONDS = 14 * 86400;

    private const PAGE_SIZE = 50;

    private const RETURN_TYPES = ['ESCROW_VERIFIED_MINUS', 'SELLER_COMPENSATE_ADD'];

    private const MAX_OLD_CHECKS = 40;

    private const OLD_CHECK_TTL = 30 * 86400;

    private const ESCROW_RECHECK_TTL = 7 * 86400;

    private const MAX_ESCROW_RECHECKS = 60;

    private const MAX_PAGES_PER_WINDOW = 100;

    public function __construct(private readonly ShopeeClient $client)
    {
    }

    public static function attention(): array
    {
        return [
            'channel' => 'shopee',
            'table' => 'shopee_orders',
            'store_column' => 'shopee_setting_id',
            'id_column' => 'order_sn',
            'payable' => ['COMPLETED'],
            'received_column' => 'payout_amount',
            'short_expected_sql' => "CASE WHEN JSON_VALID(fees) THEN COALESCE(JSON_VALUE(fees, '$.order_income.escrow_amount'), JSON_VALUE(fees, '$.escrow_amount')) END",
            'order_route' => 'ext.shopee.orders.show',
            'order_param' => 'orderSn',
            'order_key' => 'ref',
        ];
    }

    public function sync(ShopeeSetting $store, int $days, ?callable $say = null): array
    {
        $say ??= static function (string $line): void {};
        $out = ['ok' => true, 'message' => '', 'waiting' => 0, 'requests' => 0, 'paid' => 0, 'releasing' => 0, 'returned' => 0, 'checked' => 0, 'no_payout' => 0];

        $auth = ShopeeSetting::activeAuth($store->decrypted());
        if (! $auth['complete']) {
            return ['ok' => false, 'message' => 'This store is not connected to Shopee.'] + $out;
        }

        $payable = ShopeeOrder::query()->where('shopee_setting_id', $store->id)->where('status', 'COMPLETED');

        $this->checkOldOrders($store, $auth, $days, $out);
        $out['no_payout'] = PayoutStatus::retireOlderThan($payable, $days);

        $waiting = (clone $payable)
            ->where('order_created_at', '>=', now()->subDays($days))
            ->where(fn ($q) => $q->whereNull('payout_status')
                ->orWhereNotIn('payout_status', [PayoutStatus::PAID, PayoutStatus::RETURNED])
                ->orWhere(fn ($p) => $p->where('payout_status', PayoutStatus::PAID)->whereNull('payout_amount')))
            ->get(['id', 'order_sn', 'order_created_at', 'order_updated_at', 'payout_status']);
        $out['waiting'] = $waiting->count();

        if ($waiting->isEmpty()) {
            $out['message'] = 'No completed orders are waiting for a payout'
                . ($out['no_payout'] ? "; {$out['no_payout']} older than {$days} days marked No payout found." : '.');
            $say($out['message']);

            return $out;
        }

        // Start a day before the oldest waiting order so one placed late on a UTC day is covered.
        $from = (int) $waiting->min(fn ($o) => $o->order_created_at?->getTimestamp() ?? time()) - 86400;
        $bySn = $waiting->keyBy('order_sn');
        $paidAt = [];
        $credited = [];
        $settled = [];

        for ($windowFrom = $from; $windowFrom < time() && count($paidAt) < $bySn->count(); $windowFrom += self::WINDOW_SECONDS) {
            $windowTo = min($windowFrom + self::WINDOW_SECONDS - 1, time());
            $page = 1;
            do {
                $res = $this->client->shopGet(
                    $auth['mode'], (int) $auth['partner_id'], (string) $auth['partner_key'],
                    (string) $auth['access_token'], (int) $auth['shop_id'],
                    '/api/v2/payment/get_wallet_transaction_list',
                    ['page_no' => $page, 'page_size' => self::PAGE_SIZE, 'create_time_from' => $windowFrom, 'create_time_to' => $windowTo]
                );
                $out['requests']++;
                $body = is_array($res['body'] ?? null) ? $res['body'] : [];
                if (! ($res['ok'] ?? false) || (string) ($body['error'] ?? '') !== '') {
                    $why = trim((string) ($body['message'] ?? $body['error'] ?? '')) ?: ('HTTP ' . ($res['status'] ?? 0));
                    $out['ok'] = false;
                    $out['message'] = 'Shopee did not list the wallet transactions from ' . date('Y-m-d', $windowFrom) . ': ' . $why
                        . ". {$out['paid']} found before it are kept.";
                    break 2;
                }

                $response = is_array($body['response'] ?? null) ? $body['response'] : [];
                foreach ((array) ($response['transaction_list'] ?? []) as $trx) {
                    $sn = (string) ($trx['order_sn'] ?? '');
                    $type = (string) ($trx['transaction_type'] ?? '');
                    if ($sn === '' || ! $bySn->has($sn) || ($trx['status'] ?? '') !== 'COMPLETED') {
                        continue;
                    }
                    $at = (int) ($trx['create_time'] ?? 0);
                    if ($type === 'ESCROW_VERIFIED_ADD') {
                        $paidAt[$sn] = isset($paidAt[$sn]) ? min($paidAt[$sn], $at) : $at;
                        $credited[$sn] = ($credited[$sn] ?? 0.0) + (float) ($trx['amount'] ?? 0);
                    } elseif (in_array($type, self::RETURN_TYPES, true)) {
                        $settled[$sn][] = [$type, (float) ($trx['amount'] ?? 0), $at];
                    }
                }
                $more = (bool) ($response['more'] ?? false);
                $page++;
            } while ($more && $page <= self::MAX_PAGES_PER_WINDOW);
        }

        foreach ($waiting as $order) {
            if (isset($paidAt[$order->order_sn])) {
                $at = $paidAt[$order->order_sn];
                ShopeeOrder::query()->whereKey($order->id)->update([
                    'payout_status' => PayoutStatus::PAID,
                    'paid_at' => $at > 0 ? date('Y-m-d H:i:s', $at) : now(),
                    'payout_amount' => round($credited[$order->order_sn] ?? 0.0, 2),
                ]);
                if ($order->payout_status !== PayoutStatus::PAID) {
                    $out['paid']++;
                }
            } elseif ($order->payout_status === PayoutStatus::PAID) {
                continue;
            } elseif ($this->settleIfReturned($auth, $order, $settled[$order->order_sn] ?? [], $this->overdue($order))) {
                $out['returned']++;
            } elseif ($out['ok'] && $order->payout_status !== PayoutStatus::RELEASING) {
                ShopeeOrder::query()->whereKey($order->id)->update(['payout_status' => PayoutStatus::RELEASING, 'paid_at' => null]);
                $out['releasing']++;
            } elseif ($order->payout_status === PayoutStatus::RELEASING) {
                $out['releasing']++;
            }
        }

        $rechecked = $this->refreshStaleEscrow($store, $auth, $days);

        if ($out['ok']) {
            $out['message'] = "Payouts: {$out['paid']} paid, {$out['releasing']} releasing, {$out['returned']} returned, from {$out['requests']} wallet requests"
                . ($out['checked'] ? "; {$out['checked']} older orders looked up" : '')
                . ($rechecked ? "; escrow read again for {$rechecked} credited differently" : '')
                . ($out['no_payout'] ? "; {$out['no_payout']} older than {$days} days marked No payout found." : '.');
        }
        $say($out['message']);

        return $out;
    }

    private function refreshStaleEscrow(ShopeeSetting $store, array $auth, int $days): int
    {
        $escrow = "CAST(CASE WHEN JSON_VALID(fees) THEN COALESCE(JSON_VALUE(fees, '$.order_income.escrow_amount'), JSON_VALUE(fees, '$.escrow_amount')) END AS DECIMAL(12,2))";

        $stale = DB::table('shopee_orders')
            ->where('shopee_setting_id', $store->id)
            ->where('payout_status', PayoutStatus::PAID)
            ->whereNotNull('payout_amount')
            ->where('order_created_at', '>=', now()->subDays($days))
            ->whereRaw("ABS({$escrow} - payout_amount) > 0.009")
            ->orderByDesc('paid_at')
            ->limit(self::MAX_ESCROW_RECHECKS * 3)
            ->get(['id', 'order_sn']);

        $done = 0;
        foreach ($stale as $order) {
            if ($done >= self::MAX_ESCROW_RECHECKS || ! Cache::add('shopee:escrow-recheck:' . $order->id, 1, self::ESCROW_RECHECK_TTL)) {
                continue;
            }
            $detail = $this->escrowDetail($auth, (string) $order->order_sn);
            if ($detail === null) {
                continue;
            }
            DB::table('shopee_orders')->where('id', $order->id)->update(['fees' => json_encode($detail)]);
            $done++;
        }

        return $done;
    }

    private function settleIfReturned(array $auth, object $order, array $lines, bool $lookAnyway, ?array $detail = null): bool
    {
        $charged = array_filter($lines, fn ($l) => $l[0] === 'ESCROW_VERIFIED_MINUS');
        if ($detail === null && $charged === [] && ! ($lookAnyway && Cache::add('shopee:return-check:' . $order->id, 1, self::ESCROW_RECHECK_TTL))) {
            return false;
        }

        $detail ??= $this->escrowDetail($auth, (string) $order->order_sn);
        if ($detail === null) {
            return false;
        }
        $escrow = (float) $detail['order_income']['escrow_amount'];
        $returned = $charged !== [] || ($escrow <= 0 && ! empty($detail['return_order_sn_list']));
        if (! $returned || $escrow > 0) {
            return false;
        }

        ShopeeOrder::query()->whereKey($order->id)->update([
            'payout_status' => PayoutStatus::RETURNED,
            'paid_at' => $charged !== [] ? date('Y-m-d H:i:s', min(array_column($charged, 2))) : null,
            'payout_amount' => round($lines !== [] ? array_sum(array_column($lines, 1)) : $escrow, 2),
            'fees' => json_encode($detail),
        ]);

        return true;
    }

    private function checkOldOrders(ShopeeSetting $store, array $auth, int $days, array &$out): void
    {
        $old = ShopeeOrder::query()
            ->where('shopee_setting_id', $store->id)
            ->where('status', 'COMPLETED')
            ->where('order_created_at', '<', now()->subDays($days))
            ->where(fn ($q) => $q->whereNull('payout_status')->orWhereNotIn('payout_status', [PayoutStatus::PAID, PayoutStatus::RETURNED]))
            ->orderByDesc('order_created_at')
            ->get(['id', 'order_sn', 'order_created_at', 'payout_status'])
            ->filter(fn ($o) => ! Cache::has('shopee:old-check:' . $o->id))
            ->take(self::MAX_OLD_CHECKS);
        if ($old->isEmpty()) {
            return;
        }

        $details = [];
        foreach ($old as $o) {
            Cache::put('shopee:old-check:' . $o->id, 1, self::OLD_CHECK_TTL);
            $details[$o->order_sn] = $this->escrowDetail($auth, (string) $o->order_sn);
            $out['requests']++;
        }

        $bySn = $old->keyBy('order_sn');
        $lines = [];
        $from = (int) $old->min(fn ($o) => $o->order_created_at->getTimestamp()) - 86400;
        $until = min(time(), (int) $old->max(fn ($o) => $o->order_created_at->getTimestamp()) + 60 * 86400);
        for ($windowFrom = $from; $windowFrom < $until; $windowFrom += self::WINDOW_SECONDS) {
            $page = 1;
            do {
                $res = $this->client->shopGet(
                    $auth['mode'], (int) $auth['partner_id'], (string) $auth['partner_key'],
                    (string) $auth['access_token'], (int) $auth['shop_id'],
                    '/api/v2/payment/get_wallet_transaction_list',
                    ['page_no' => $page, 'page_size' => self::PAGE_SIZE, 'create_time_from' => $windowFrom, 'create_time_to' => min($windowFrom + self::WINDOW_SECONDS - 1, $until)]
                );
                $out['requests']++;
                $body = is_array($res['body'] ?? null) ? $res['body'] : [];
                if (! ($res['ok'] ?? false) || (string) ($body['error'] ?? '') !== '') {
                    return;
                }
                $response = is_array($body['response'] ?? null) ? $body['response'] : [];
                foreach ((array) ($response['transaction_list'] ?? []) as $trx) {
                    $sn = (string) ($trx['order_sn'] ?? '');
                    if ($bySn->has($sn) && ($trx['status'] ?? '') === 'COMPLETED') {
                        $lines[$sn][] = [(string) ($trx['transaction_type'] ?? ''), (float) ($trx['amount'] ?? 0), (int) ($trx['create_time'] ?? 0)];
                    }
                }
                $page++;
            } while (($response['more'] ?? false) && $page <= self::MAX_PAGES_PER_WINDOW);
        }

        foreach ($old as $o) {
            $mine = $lines[$o->order_sn] ?? [];
            $paid = array_filter($mine, fn ($l) => $l[0] === 'ESCROW_VERIFIED_ADD');
            $detail = $details[$o->order_sn] ?? null;
            if ($paid !== []) {
                ShopeeOrder::query()->whereKey($o->id)->update([
                    'payout_status' => PayoutStatus::PAID,
                    'paid_at' => date('Y-m-d H:i:s', min(array_column($paid, 2))),
                    'payout_amount' => round(array_sum(array_column($paid, 1)), 2),
                ] + ($detail ? ['fees' => json_encode($detail)] : []));
                $out['paid']++;
                $out['checked']++;
            } elseif ($detail !== null && $this->settleIfReturned($auth, $o, array_values(array_filter($mine, fn ($l) => in_array($l[0], self::RETURN_TYPES, true))), true, $detail)) {
                $out['returned']++;
                $out['checked']++;
            }
        }
    }

    private function overdue(object $order): bool
    {
        $done = $order->order_updated_at ?? $order->order_created_at;

        return $done !== null && $done->lt(now()->subDays(\App\Services\Payouts\PayoutAttention::OVERDUE_DAYS));
    }

    private function escrowDetail(array $auth, string $orderSn): ?array
    {
        $res = $this->client->shopGet(
            $auth['mode'], (int) $auth['partner_id'], (string) $auth['partner_key'],
            (string) $auth['access_token'], (int) $auth['shop_id'],
            '/api/v2/payment/get_escrow_detail', ['order_sn' => $orderSn]
        );
        ShopeeApiLog::safeCreate([
            'pack' => 'shopee.payouts.get_escrow_detail',
            'method' => 'GET',
            'api_path' => '/api/v2/payment/get_escrow_detail',
            'auth_required' => true,
            'request_params' => ['order_sn' => $orderSn],
            'response_status' => (int) ($res['status'] ?? 0),
            'ok' => (bool) ($res['ok'] ?? false),
            'response_body' => is_array($res['body'] ?? null) ? $res['body'] : null,
        ]);

        $body = is_array($res['body'] ?? null) ? $res['body'] : [];
        $detail = $body['response'] ?? null;
        if (! ($res['ok'] ?? false) || (string) ($body['error'] ?? '') !== ''
            || ! is_array($detail) || ! is_numeric($detail['order_income']['escrow_amount'] ?? null)) {
            return null;
        }

        return $detail;
    }
}
