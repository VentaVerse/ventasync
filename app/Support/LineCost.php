<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * What an order line costs, read from the catalogue at the moment the line is
 * written. One place for it because four syncs (Shopee, Lazada, TikTok,
 * Venta) and the backfill command all used to carry their own copy of the
 * same twenty lines, and the copies drifted.
 *
 * The rule, decided with the owner 2026-08-27: a variation with a cost of its
 * own is costed at that; a variation without one falls back to the PARENT's
 * cost. "Of its own" means an amount was entered for it. Since 2026-08-22 a
 * variation's absolute_cost is composed as
 *
 *   amount + (parent percentage / 100 * variation price) + parent additional
 *
 * so a variation with no amount still carries a non-zero absolute_cost made
 * only of the parent's commission share and freight. That number is not a
 * cost, it is overhead on nothing, and taking it as the variation's cost is
 * exactly what put a 240 peso "cost" on lines that used to read 520 and
 * made the margin reports wrong. The check below sees through it.
 *
 * Legacy rows are read the OpenCart way: an absolute_cost typed before the
 * formula existed has no percentage or additional stored beside it, so it is
 * taken whole; a row with no absolute_cost at all applies its +/- cost delta
 * to the parent.
 */
final class LineCost
{
    /** Below this, a difference between two costs is rounding, not a number. */
    private const EPSILON = 0.00005;

    /**
     * @param int $productId       catalogue product on the line; 0 when unmatched
     * @param int $optionValueId   product_option_value_id of the variation sold; 0 when none
     */
    public static function resolve(int $productId, int $optionValueId = 0): float
    {
        if ($productId <= 0) {
            return 0.0;
        }

        $pfx = (string) config('catalog.prefix');

        $parent = (float) (DB::table($pfx . 'product')
            ->where('product_id', $productId)
            ->value('cost') ?? 0);

        if ($optionValueId <= 0) {
            return $parent;
        }

        $row = DB::table($pfx . 'product_option_value')
            ->where('product_option_value_id', $optionValueId)
            ->first(['cost', 'cost_prefix', 'cost_amount', 'cost_percentage', 'cost_additional', 'absolute_price', 'absolute_cost']);

        if ($row === null) {
            return $parent;
        }

        $own = self::ownCost($row);
        if ($own !== null) {
            return $own;
        }

        // OpenCart's +/- delta, honoured only on a row that is purely that:
        // no amount, no absolute, no formula parts. A received purchase
        // order used to write the unit TOTAL into this column, and read as a
        // delta that doubled the cost (10,220 + 10,220 on the GP200).
        $delta = (float) ($row->cost ?? 0);
        $pureLegacy = (float) ($row->cost_amount ?? 0) <= 0
            && (float) ($row->absolute_cost ?? 0) <= 0
            && (float) ($row->cost_percentage ?? 0) == 0.0
            && (float) ($row->cost_additional ?? 0) == 0.0;
        if ($delta > 0 && $pureLegacy) {
            return ($row->cost_prefix ?? '+') === '-' ? $parent - $delta : $parent + $delta;
        }

        return $parent;
    }

    /**
     * The variation's own unit cost, or null when it has none and the parent
     * should stand in. A product_option_value row, or anything with the same
     * columns.
     */
    public static function ownCost(object $row): ?float
    {
        $absolute = (float) ($row->absolute_cost ?? 0);
        $amount = (float) ($row->cost_amount ?? 0);

        if ($amount > 0) {
            // An amount with no absolute beside it is a row a purchase order
            // updated before absolute_cost was written on receipt. Compose
            // the unit cost the way the form would.
            return $absolute > 0
                ? $absolute
                : round($amount
                    + ((float) ($row->cost_percentage ?? 0) / 100 * (float) ($row->absolute_price ?? 0))
                    + (float) ($row->cost_additional ?? 0), 4);
        }

        if ($absolute <= 0) {
            return null;
        }

        // No amount. Own only if the absolute cost holds more than the
        // parent's overhead would compose on its own: a legacy row typed as
        // one number has no overhead stored, so it passes; a formula row
        // with an empty amount equals its overhead exactly, so it does not.
        $overhead = ((float) ($row->cost_percentage ?? 0) / 100 * (float) ($row->absolute_price ?? 0))
            + (float) ($row->cost_additional ?? 0);

        return $absolute > $overhead + self::EPSILON ? $absolute : null;
    }

    /**
     * The same question for a product_option_combinations row, which stores
     * no percentage or additional beside its absolute_cost. There the amount
     * is the whole answer: the 2026-08-22 migration backfilled cost_amount
     * from every absolute_cost that existed, so a combination with an
     * absolute_cost and no amount can only be a formula row with an empty
     * amount, and that is overhead, not a cost.
     */
    public static function ownCombinationCost(object $combination): ?float
    {
        $absolute = (float) ($combination->absolute_cost ?? 0);
        $amount = (float) ($combination->cost_amount ?? 0);

        return ($absolute > 0 && $amount > 0) ? $absolute : null;
    }
}
