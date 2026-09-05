<?php

namespace Extensions\opencart\Services\OpenCart;

use Extensions\opencart\Models\OpenCartSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hard delete of an OpenCart store (the Shopee shape, #204, ported
 * 2026-09-06): one transaction takes everything the store owns in the
 * ERP. Core orders already imported stay, under the store's name.
 * Nothing on the OpenCart store itself is touched.
 */
final class OpenCartStoreDeleter
{
    private const TABLES = [
        'opencart_sync_log',
        'opencart_order_status_map',
        'opencart_product_links',
        'opencart_product_groups',
    ];

    /** @return array<string,int> rows removed per table */
    public function delete(OpenCartSetting $store): array
    {
        return DB::transaction(function () use ($store) {
            $removed = [];
            if (Schema::hasTable('opencart_product_group_products')) {
                $groupIds = DB::table('opencart_product_groups')->where('opencart_setting_id', $store->id)->pluck('id');
                $removed['opencart_product_group_products'] = $groupIds->isEmpty() ? 0
                    : DB::table('opencart_product_group_products')->whereIn('opencart_product_group_id', $groupIds)->delete();
            }
            foreach (self::TABLES as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'opencart_setting_id')) {
                    continue;
                }
                $removed[$table] = DB::table($table)->where('opencart_setting_id', $store->id)->delete();
            }
            if (Schema::hasTable('scheduled_jobs')) {
                $removed['scheduled_jobs'] = DB::table('scheduled_jobs')->where('integration', 'opencart')->where('store_id', $store->id)->delete();
            }
            DB::table('opencart_settings')->where('id', $store->id)->delete();

            return $removed;
        });
    }
}
