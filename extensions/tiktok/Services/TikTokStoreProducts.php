<?php

namespace Extensions\tiktok\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class TikTokStoreProducts
{
    public static function query(): Builder
    {
        $storeId = app()->bound('tiktok.route-store') ? (int) app('tiktok.route-store')->id : null;

        $listings = DB::table('tiktok_listings')->select('product_id')
            ->when($storeId !== null, fn (Builder $q) => $q->where('tiktok_setting_id', $storeId));
        $pivots = DB::table('tiktok_product_group_products as tp')
            ->join('tiktok_product_groups as tg', 'tg.id', '=', 'tp.tiktok_product_group_id')
            ->whereNotNull('tp.tiktok_product_id')
            ->when($storeId !== null, fn (Builder $q) => $q->where('tg.tiktok_setting_id', $storeId))
            ->select('tp.product_id');

        return $listings->union($pivots);
    }

    public static function has(int $productId): bool
    {
        return DB::query()->fromSub(self::query(), 'os')->where('os.product_id', $productId)->exists();
    }

    public static function count(): int
    {
        return (int) DB::query()->fromSub(self::query(), 'os')->count();
    }

    public static function listedIds(): array
    {
        $storeId = app()->bound('tiktok.route-store') ? (int) app('tiktok.route-store')->id : null;

        $fromListings = DB::table('tiktok_listings')->whereNotNull('tiktok_product_id')
            ->when($storeId !== null, fn (Builder $q) => $q->where('tiktok_setting_id', $storeId))
            ->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        $fromPivots = DB::table('tiktok_product_group_products as tp')
            ->join('tiktok_product_groups as tg', 'tg.id', '=', 'tp.tiktok_product_group_id')
            ->whereNotNull('tp.tiktok_product_id')
            ->when($storeId !== null, fn (Builder $q) => $q->where('tg.tiktok_setting_id', $storeId))
            ->pluck('tp.product_id')->map(fn ($v) => (int) $v)->all();

        return array_values(array_unique(array_merge($fromListings, $fromPivots)));
    }
}
