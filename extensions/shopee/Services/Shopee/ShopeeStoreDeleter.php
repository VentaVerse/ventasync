<?php

namespace Extensions\shopee\Services\Shopee;

use Extensions\shopee\Models\ShopeeSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hard delete of a store (the owner, 2026-09-05): everything the store owns,
 * child tables first because the foreign keys restrict, then the row.
 * No restore. Core orders are not touched: they carry the store's id
 * and name themselves, so sales already imported keep the name they
 * were sold under and stay inside the channel's totals.
 */
final class ShopeeStoreDeleter
{
    /**
     * Child-first. Rows that hang off a product group go with the group
     * (the pivot cascades). A table missing on an older schema is skipped.
     */
    private const TABLES = [
        'shopee_api_logs',
        'shopee_item_cache',
        'shopee_unmatched_items',
        'shopee_product_links',
        'shopee_listings',
        'shopee_product_groups',
        'shopee_logistics',
        'shopee_returns',
        'shopee_orders',
    ];

    /** @return array<string,int> rows removed per table */
    public function delete(ShopeeSetting $store): array
    {
        return DB::transaction(function () use ($store) {
            $removed = [];
            foreach (self::TABLES as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'shopee_setting_id')) {
                    continue;
                }
                $removed[$table] = DB::table($table)->where('shopee_setting_id', $store->id)->delete();
            }

            // Per-store automation rows carry the store id without a key.
            $removed['scheduled_jobs'] = DB::table('scheduled_jobs')
                ->where('integration', 'shopee')->where('store_id', $store->id)->delete();

            DB::table('shopee_settings')->where('id', $store->id)->delete();

            return $removed;
        });
    }
}
