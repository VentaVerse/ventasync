<?php

namespace Extensions\VentaCart\Services\VentaCart;

use Extensions\VentaCart\Models\VentaCartStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class VentaCartStoreDeleter
{
    private const TABLES = [
        'ch_ventacart_api_logs',
        'ch_ventacart_sync_logs',
        'ch_ventacart_order_status_map',
        'ch_ventacart_status_placements',
        'ch_ventacart_orders',
        'ch_ventacart_listings',
        'ch_ventacart_listing_links',
        'ch_ventacart_product_groups',
        'ch_ventacart_categories',
        'ch_ventacart_brands',
    ];

    public function delete(VentaCartStore $store): array
    {
        return DB::transaction(function () use ($store) {
            $removed = [];
            if (Schema::hasTable('ch_ventacart_order_products')) {
                $orderIds = DB::table('ch_ventacart_orders')->where('store_id', $store->id)->pluck('id');
                $removed['ch_ventacart_order_products'] = $orderIds->isEmpty() ? 0
                    : DB::table('ch_ventacart_order_products')->whereIn('ventacart_order_id', $orderIds)->delete();
            }
            if (Schema::hasTable('ch_ventacart_product_group_products')) {
                $groupIds = DB::table('ch_ventacart_product_groups')->where('store_id', $store->id)->pluck('id');
                $removed['ch_ventacart_product_group_products'] = $groupIds->isEmpty() ? 0
                    : DB::table('ch_ventacart_product_group_products')->whereIn('ventacart_product_group_id', $groupIds)->delete();
            }
            foreach (self::TABLES as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'store_id')) {
                    continue;
                }
                $removed[$table] = DB::table($table)->where('store_id', $store->id)->delete();
            }
            if (Schema::hasTable('scheduled_jobs')) {
                $removed['scheduled_jobs'] = DB::table('scheduled_jobs')
                    ->where('extension', 'ventacart')->where('store_id', $store->id)->delete();
            }
            DB::table('ch_ventacart_stores')->where('id', $store->id)->delete();

            return $removed;
        });
    }
}
