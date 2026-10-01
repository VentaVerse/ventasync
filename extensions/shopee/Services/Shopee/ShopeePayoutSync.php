<?php

namespace Extensions\shopee\Services\Shopee;

use App\Support\PayoutStatus;
use Extensions\shopee\Models\ShopeeApiLog;
use Extensions\shopee\Models\ShopeeOrder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Extensions\shopee\Models\ShopeeSetting;

/**
 * Which completed Shopee orders have been paid out (the owner, 2026-10-01).
 *
 * An order is Paid when Shopee credits its escrow to the seller's wallet: an
 * ESCROW_VERIFIED_ADD wallet transaction, COMPLETED, dated by its create
 * time (App\Support\PayoutStatus). Shopee answers the wallet a fortnight at
 * a time - fifteen days or more is "time period too large" - so this walks
 * back in 14-day windows from the oldest order still waiting, and stops as
 * soon as every one of them is found.
 *
 * It used to read only the last 14 days, every minute, inside the order
 * sync: 5,760 requests a day over the same fortnight, and any order whose
 * escrow came earlier stayed Releasing for ever (472 did). It runs as its
 * own hourly job now.
 *
 * Writes payout_status, paid_at and payout_amount (what the wallet was
 * credited). Never stock, never the order.
 *
 * The escrow on record is read once, when the order ships, before Shopee
 * has charged the final shipping fee: on 1 Oct 2026, 15 paid orders showed
 * a promised escrow ₱8 to ₱90 above what was credited, and Shopee's own
 * escrow detail, read again, matched the credit to the centavo. So a paid
 * order whose credit differs from its recorded escrow has the escrow read
 * again (refreshStaleEscrow) before anything calls it Paid short. That
 * re-read is the only time this writes fees.
 */
class ShopeePayoutSync
{
    /** Shopee refuses a wallet window of fifteen days or more. */
    private const WINDOW_SECONDS = 14 * 86400;

    private const PAGE_SIZE = 50;

    /** An order's escrow is read again at most once in this many seconds. */
    private const ESCROW_RECHECK_TTL = 7 * 86400;

    /** Escrow re-reads per run, so one run never turns into hundreds of calls. */
    private const MAX_ESCROW_RECHECKS = 60;

    /** A window holding more pages than this is read no further, and said so. */
    private const MAX_PAGES_PER_WINDOW = 100;

    public function __construct(private readonly ShopeeClient $client)
    {
    }

    /**
     * How a store's Payouts page reads Shopee orders (App\Services\Payouts\
     * PayoutAttention). Shopee alone has a credited amount of its own to
     * hold against the escrow it promised, so only Shopee can be Paid short.
     *
     * @return array<string, mixed>
     */
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

    /**
     * @param  (callable(string): void)|null  $say  progress lines, for a console
     * @return array{ok: bool, message: string, waiting: int, requests: int, paid: int, releasing: int, no_payout: int}
     */
    public function sync(ShopeeSetting $store, int $days, ?callable $say = null): array
    {
        $say ??= static function (string $line): void {};
        $out = ['ok' => true, 'message' => '', 'waiting' => 0, 'requests' => 0, 'paid' => 0, 'releasing' => 0, 'no_payout' => 0];

        $auth = ShopeeSetting::activeAuth($store->decrypted());
        if (! $auth['complete']) {
            return ['ok' => false, 'message' => 'This store is not connected to Shopee.'] + $out;
        }

        $payable = ShopeeOrder::query()->where('shopee_setting_id', $store->id)->where('status', 'COMPLETED');
        $out['no_payout'] = PayoutStatus::retireOlderThan($payable, $days);

        $waiting = (clone $payable)
            ->where('order_created_at', '>=', now()->subDays($days))
            // Paid orders with no credited amount yet are read again for it,
            // once (the amount arrived on 2026-10-01; nothing earlier has one).
            ->where(fn ($q) => $q->whereNull('payout_status')->orWhere('payout_status', '!=', PayoutStatus::PAID)->orWhereNull('payout_amount'))
            ->get(['id', 'order_sn', 'order_created_at', 'payout_status']);
        $out['waiting'] = $waiting->count();

        if ($waiting->isEmpty()) {
            $out['message'] = 'No completed orders are waiting for a payout'
                . ($out['no_payout'] ? "; {$out['no_payout']} older than {$days} days marked No payout found." : '.');
            $say($out['message']);

            return $out;
        }

        // A day before the oldest waiting order, so one placed late on a UTC day is covered.
        $from = (int) $waiting->min(fn ($o) => $o->order_created_at?->getTimestamp() ?? time()) - 86400;
        $bySn = $waiting->keyBy('order_sn');
        $paidAt = [];   // order_sn => unix time the escrow reached the wallet
        $credited = []; // order_sn => what the wallet was credited for it

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
                    if ($sn === '' || ! $bySn->has($sn)
                        || ($trx['transaction_type'] ?? '') !== 'ESCROW_VERIFIED_ADD'
                        || ($trx['status'] ?? '') !== 'COMPLETED') {
                        continue;
                    }
                    $at = (int) ($trx['create_time'] ?? 0);
                    $paidAt[$sn] = isset($paidAt[$sn]) ? min($paidAt[$sn], $at) : $at;
                    $credited[$sn] = ($credited[$sn] ?? 0.0) + (float) ($trx['amount'] ?? 0);
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
                // Paid before this read and its credit is outside it: it stays
                // Paid, with no amount to hold against the escrow.
                continue;
            } elseif ($out['ok'] && $order->payout_status !== PayoutStatus::RELEASING) {
                // Only a full read says "not yet"; a refused one leaves the order as it was.
                ShopeeOrder::query()->whereKey($order->id)->update(['payout_status' => PayoutStatus::RELEASING, 'paid_at' => null]);
                $out['releasing']++;
            } elseif ($order->payout_status === PayoutStatus::RELEASING) {
                $out['releasing']++;
            }
        }

        $rechecked = $this->refreshStaleEscrow($store, $auth, $days);

        if ($out['ok']) {
            $out['message'] = "Payouts: {$out['paid']} paid, {$out['releasing']} releasing, from {$out['requests']} wallet requests"
                . ($rechecked ? "; escrow read again for {$rechecked} credited differently" : '')
                . ($out['no_payout'] ? "; {$out['no_payout']} older than {$days} days marked No payout found." : '.');
        }
        $say($out['message']);

        return $out;
    }

    /**
     * Read Shopee's escrow again for paid orders credited differently from
     * the escrow on record, and keep the final one. Returns how many.
     */
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
            $res = $this->client->shopGet(
                $auth['mode'], (int) $auth['partner_id'], (string) $auth['partner_key'],
                (string) $auth['access_token'], (int) $auth['shop_id'],
                '/api/v2/payment/get_escrow_detail', ['order_sn' => $order->order_sn]
            );
            ShopeeApiLog::safeCreate([
                'pack' => 'shopee.payouts.get_escrow_detail',
                'method' => 'GET',
                'api_path' => '/api/v2/payment/get_escrow_detail',
                'auth_required' => true,
                'request_params' => ['order_sn' => $order->order_sn],
                'response_status' => (int) ($res['status'] ?? 0),
                'ok' => (bool) ($res['ok'] ?? false),
                'response_body' => is_array($res['body'] ?? null) ? $res['body'] : null,
            ]);

            $body = is_array($res['body'] ?? null) ? $res['body'] : [];
            $detail = $body['response'] ?? null;
            if (! ($res['ok'] ?? false) || (string) ($body['error'] ?? '') !== ''
                || ! is_array($detail) || ! is_numeric($detail['order_income']['escrow_amount'] ?? null)) {
                continue;
            }
            DB::table('shopee_orders')->where('id', $order->id)->update(['fees' => json_encode($detail)]);
            $done++;
        }

        return $done;
    }
}
