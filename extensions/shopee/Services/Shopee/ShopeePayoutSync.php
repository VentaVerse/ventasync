<?php

namespace Extensions\shopee\Services\Shopee;

use App\Support\PayoutStatus;
use Extensions\shopee\Models\ShopeeOrder;
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
 * Writes only payout_status and paid_at. Never stock, never the order.
 */
class ShopeePayoutSync
{
    /** Shopee refuses a wallet window of fifteen days or more. */
    private const WINDOW_SECONDS = 14 * 86400;

    private const PAGE_SIZE = 50;

    /** A window holding more pages than this is read no further, and said so. */
    private const MAX_PAGES_PER_WINDOW = 100;

    public function __construct(private readonly ShopeeClient $client)
    {
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
            ->where(fn ($q) => $q->whereNull('payout_status')->orWhere('payout_status', '!=', PayoutStatus::PAID))
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
                ]);
                $out['paid']++;
            } elseif ($out['ok'] && $order->payout_status !== PayoutStatus::RELEASING) {
                // Only a full read says "not yet"; a refused one leaves the order as it was.
                ShopeeOrder::query()->whereKey($order->id)->update(['payout_status' => PayoutStatus::RELEASING, 'paid_at' => null]);
                $out['releasing']++;
            } elseif ($order->payout_status === PayoutStatus::RELEASING) {
                $out['releasing']++;
            }
        }

        if ($out['ok']) {
            $out['message'] = "Payouts: {$out['paid']} paid, {$out['releasing']} releasing, from {$out['requests']} wallet requests"
                . ($out['no_payout'] ? "; {$out['no_payout']} older than {$days} days marked No payout found." : '.');
        }
        $say($out['message']);

        return $out;
    }
}
