<?php

namespace Extensions\venta\Services\Venta;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * "This store's list" (#201, on Venta since 2026-09-06): a catalog product
 * is on a Venta store when it has a listing row, a link, or a place in one
 * of the store's groups. One query shape for the Listings page, the Add
 * products slide-over and the state engine, the WooCommerce shape.
 */
final class VentaStoreProducts
{
    /** A subquery of distinct product_id for the store. */
    public static function query(int $storeId): Builder
    {
        $listings = DB::table('venta_listings')->where('venta_setting_id', $storeId)->select('product_id');
        $links = DB::table('venta_product_links')->where('venta_setting_id', $storeId)->select('product_id');
        $pivots = DB::table('venta_product_group_products as pv')
            ->join('venta_product_groups as g', 'g.id', '=', 'pv.venta_product_group_id')
            ->where('g.venta_setting_id', $storeId)
            ->select('pv.product_id');

        return DB::query()->fromSub($listings->union($links)->union($pivots), 'sp')->select('sp.product_id')->distinct();
    }

    /** @return list<int> */
    public static function ids(int $storeId): array
    {
        return self::query($storeId)->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    public static function has(int $storeId, int $productId): bool
    {
        return self::query($storeId)->where('sp.product_id', $productId)->exists();
    }
}
