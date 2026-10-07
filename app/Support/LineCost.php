<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class LineCost
{
    private const EPSILON = 0.00005;

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

    public static function ownCost(object $row): ?float
    {
        $absolute = (float) ($row->absolute_cost ?? 0);
        $amount = (float) ($row->cost_amount ?? 0);

        if ($amount > 0) {
            return $absolute > 0
                ? $absolute
                : round($amount
                    + ((float) ($row->cost_percentage ?? 0) / 100 * (float) ($row->absolute_price ?? 0))
                    + (float) ($row->cost_additional ?? 0), 4);
        }

        if ($absolute <= 0) {
            return null;
        }

        $overhead = ((float) ($row->cost_percentage ?? 0) / 100 * (float) ($row->absolute_price ?? 0))
            + (float) ($row->cost_additional ?? 0);

        return $absolute > $overhead + self::EPSILON ? $absolute : null;
    }

    public static function ownCombinationCost(object $combination): ?float
    {
        $absolute = (float) ($combination->absolute_cost ?? 0);
        $amount = (float) ($combination->cost_amount ?? 0);

        return ($absolute > 0 && $amount > 0) ? $absolute : null;
    }
}
