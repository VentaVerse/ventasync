<?php

namespace Extensions\shopee\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class ShopeeStoreProducts
{
    public static function query(): Builder
    {
        $storeId = app()->bound('shopee.route-store') ? (int) app('shopee.route-store')->id : null;

        $listings = DB::table('shopee_listings')->select('product_id')
            ->when($storeId !== null, fn (Builder $q) => $q->where('shopee_setting_id', $storeId));
        $links = DB::table('shopee_product_links')->select('product_id')
            ->when($storeId !== null, fn (Builder $q) => $q->where('shopee_setting_id', $storeId));

        return $listings->union($links);
    }

    public static function has(int $productId): bool
    {
        return DB::query()->fromSub(self::query(), 'os')->where('os.product_id', $productId)->exists();
    }

    public static function count(): int
    {
        return (int) DB::query()->fromSub(self::query(), 'os')->count();
    }

}
