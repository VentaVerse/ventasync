<?php

namespace Extensions\shopee\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The products THIS STORE carries (#201, the owner 2026-09-05).
 *
 * A store's Listings is its own list, not the catalogue. A product is on the
 * store once it has a listing record here (Add products, Import) or a link
 * row here (a push, a match, an adopted stray). One derived set, read by the
 * Listings table, the Add products panel and the product group panel alike,
 * so nothing needs a backfill and a linked product can never be invisible on
 * its own store. Unlike Lazada, whose one listing row is both the membership
 * and the record, Shopee keeps the record and the link apart - hence the union.
 */
final class ShopeeStoreProducts
{
    /** One row per product id, for the store bound to the request (every store when none is). */
    public static function query(): Builder
    {
        $storeId = app()->bound('shopee.route-store') ? (int) app('shopee.route-store')->id : null;

        $listings = DB::table('shopee_listings')->select('product_id')
            ->when($storeId !== null, fn (Builder $q) => $q->where('shopee_setting_id', $storeId));
        $links = DB::table('shopee_product_links')->select('product_id')
            ->when($storeId !== null, fn (Builder $q) => $q->where('shopee_setting_id', $storeId));

        // UNION (not UNION ALL) dedups, so this joins as at most one row per product.
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
