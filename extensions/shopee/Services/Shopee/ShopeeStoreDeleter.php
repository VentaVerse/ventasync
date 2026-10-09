<?php

namespace Extensions\Shopee\Services\Shopee;

use Extensions\Shopee\Models\ShopeeStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ShopeeStoreDeleter
{
    private const TABLES = [
        'ch_shopee_api_logs',
        'ch_shopee_item_cache',
        'shopee_unmatched_items',
        'ch_shopee_listing_links',
        'ch_shopee_listings',
        'ch_shopee_product_groups',
        'ch_shopee_logistics',
        'ch_shopee_returns',
        'ch_shopee_orders',
    ];

    public function delete(ShopeeStore $store): array
    {
        return DB::transaction(function () use ($store) {
            $removed = [];

            $productIds = DB::table('ch_shopee_listings')->where('store_id', $store->id)->pluck('product_id')
                ->merge(DB::table('ch_shopee_listing_links')->where('store_id', $store->id)->pluck('product_id'))
                ->map(fn ($v) => (int) $v)->unique()->values()->all();
            app(ShopeeListingStates::class)->forStore($store)->clearErrors($productIds);

            if (Schema::hasTable('ch_shopee_product_group_products')) {
                $groupIds = DB::table('ch_shopee_product_groups')->where('store_id', $store->id)->pluck('id');
                $removed['ch_shopee_product_group_products'] = $groupIds->isEmpty() ? 0
                    : DB::table('ch_shopee_product_group_products')->whereIn('shopee_product_group_id', $groupIds)->delete();
            }

            foreach (self::TABLES as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'store_id')) {
                    continue;
                }
                $removed[$table] = DB::table($table)->where('store_id', $store->id)->delete();
            }

            $removed['scheduled_jobs'] = DB::table('scheduled_jobs')
                ->where('extension', 'shopee')->where('store_id', $store->id)->delete();

            DB::table('ch_shopee_stores')->where('id', $store->id)->delete();

            return $removed;
        });
    }
}
