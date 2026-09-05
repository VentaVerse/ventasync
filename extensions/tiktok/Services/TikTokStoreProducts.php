<?php

namespace Extensions\tiktok\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The products THIS STORE carries (#201, the owner 2026-09-05).
 *
 * A store's Listings is its own list, not the catalogue. A product is on the
 * store once it has a listing record here (Add products, Import, any push
 * since the record carried the truth) or a group pivot here that a push
 * before that gave a TikTok product id (the legacy truth the pages still
 * honour). One derived set, read by the Listings table, the Add products
 * panel and the product group panel alike, so nothing needs a backfill.
 */
final class TikTokStoreProducts
{
    /** One row per product id, for the store bound to the request (every store when none is). */
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

        // UNION (not UNION ALL) dedups, so this joins as at most one row per product.
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

    /** Product ids standing on TikTok Shop for this store: a listing row with a product id, or a pushed pivot. */
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
