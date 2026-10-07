<?php

namespace Extensions\ventacart\Services\VentaCart;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class VentaCartStoreProducts
{
    public static function query(int $storeId): Builder
    {
        $listings = DB::table('ventacart_listings')->where('ventacart_setting_id', $storeId)->select('product_id');
        $links = DB::table('ventacart_product_links')->where('ventacart_setting_id', $storeId)->select('product_id');
        $pivots = DB::table('ventacart_product_group_products as pv')
            ->join('ventacart_product_groups as g', 'g.id', '=', 'pv.ventacart_product_group_id')
            ->where('g.ventacart_setting_id', $storeId)
            ->select('pv.product_id');

        return DB::query()->fromSub($listings->union($links)->union($pivots), 'sp')->select('sp.product_id')->distinct();
    }

    public static function ids(int $storeId): array
    {
        return self::query($storeId)->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    public static function has(int $storeId, int $productId): bool
    {
        return self::query($storeId)->where('sp.product_id', $productId)->exists();
    }
}
