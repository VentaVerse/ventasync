<?php

namespace Extensions\OpenCart\Services\OpenCart;

use Extensions\OpenCart\Models\OpenCartStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class OpenCartStoreDeleter
{
    private const TABLES = [
        'ch_opencart_sync_log',
        'ch_opencart_order_status_map',
        'ch_opencart_listing_links',
        'ch_opencart_product_groups',
    ];

    public function delete(OpenCartStore $store): array
    {
        return DB::transaction(function () use ($store) {
            $removed = [];
            if (Schema::hasTable('ch_opencart_product_group_products')) {
                $groupIds = DB::table('ch_opencart_product_groups')->where('store_id', $store->id)->pluck('id');
                $removed['ch_opencart_product_group_products'] = $groupIds->isEmpty() ? 0
                    : DB::table('ch_opencart_product_group_products')->whereIn('opencart_product_group_id', $groupIds)->delete();
            }
            foreach (self::TABLES as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'store_id')) {
                    continue;
                }
                $removed[$table] = DB::table($table)->where('store_id', $store->id)->delete();
            }
            if (Schema::hasTable('scheduled_jobs')) {
                $removed['scheduled_jobs'] = DB::table('scheduled_jobs')->where('extension', 'opencart')->where('store_id', $store->id)->delete();
            }
            DB::table('ch_opencart_stores')->where('id', $store->id)->delete();

            return $removed;
        });
    }
}
