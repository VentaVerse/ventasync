<?php

namespace App\Services\Orders;

use App\Integrations\Contracts\MarketplaceFeeSource;
use App\Integrations\IntegrationRegistry;
use Illuminate\Support\Facades\DB;

/**
 * THE ONE PLACE A MARKETPLACE FEE LANDS ON A CORE ORDER.
 *
 * `order_total` has carried codes for this since before the rebuild -
 * marketplace_commission, marketplace_payment_fee, marketplace_shipping_fee,
 * marketplace_other_fee - and the reports have always read them first.
 * Nothing wrote them. Every channel kept its fees in its own JSON instead
 * and the reports grew a branch per channel to go and fetch them, which is
 * the coupling the owner named on 2026-09-10.
 *
 * This writes them. The channel says what it charged (MarketplaceFeeSource);
 * this puts it on the order; the reports read the order.
 *
 * IDEMPOTENT BY CONSTRUCTION. It replaces this order's marketplace rows
 * rather than adding to them, so a re-sync, a backfill and a second
 * backfill all leave the same four rows. That matters more than it sounds:
 * the sync re-runs on every status change, and an additive writer would
 * have quietly tripled a shop's commission over a week.
 *
 * SIGNED NEGATIVE, because they are deductions and that is what every
 * legacy row in this column already is. Readers take the magnitude.
 */
class MarketplaceFeeNormalizer
{
    /** The codes this owns. Anything else in order_total is somebody else's. */
    public const CODES = [
        'commission' => ['marketplace_commission', 'Commission', 91],
        'payment' => ['marketplace_payment_fee', 'Payment fee', 92],
        'shipping' => ['marketplace_shipping_fee', 'Delivery fee', 93],
        'other' => ['marketplace_other_fee', 'Other fees', 94],
        'voucher' => ['marketplace_voucher', 'Vouchers', 95],
    ];

    public function __construct(private readonly IntegrationRegistry $registry) {}

    /**
     * Normalize one order from whichever channel claims it.
     *
     * @return bool whether a channel answered at all. False means no
     *              channel recognised the order or none had anything
     *              recorded for it - and NOTHING is written, so an order
     *              whose fees are simply not known yet keeps whatever it
     *              has rather than being blanked.
     */
    public function forOrder(int $coreOrderId): bool
    {
        foreach ($this->registry->marketplaceFeeSources() as $source) {
            $buckets = $source->feeBucketsForOrder($coreOrderId);
            if ($buckets !== null) {
                $this->write($coreOrderId, $buckets);

                return true;
            }
        }

        return false;
    }

    /**
     * Put these four figures on the order, replacing whatever was there.
     *
     * @param  array{commission?: float, payment?: float, shipping?: float, other?: float}  $buckets
     */
    public function write(int $coreOrderId, array $buckets): void
    {
        $pfx = (string) config('catalog.prefix');
        $codes = array_map(fn ($c) => $c[0], self::CODES);

        DB::transaction(function () use ($pfx, $codes, $coreOrderId, $buckets) {
            DB::table($pfx . 'order_total')
                ->where('order_id', $coreOrderId)
                ->whereIn('code', $codes)
                ->delete();

            $rows = [];
            foreach (self::CODES as $bucket => [$code, $title, $sort]) {
                $amount = round(abs((float) ($buckets[$bucket] ?? 0)), 2);
                // A zero fee is not a fact worth a row: it is the absence of
                // one, and a table of zeroes makes "never asked" and "asked,
                // nothing charged" look identical.
                if ($amount <= 0) {
                    continue;
                }
                $rows[] = [
                    'order_id' => $coreOrderId,
                    'code' => $code,
                    'title' => $title,
                    'value' => -$amount,
                    'sort_order' => $sort,
                ];
            }

            if ($rows !== []) {
                DB::table($pfx . 'order_total')->insert($rows);
            }
        });
    }
}
