<?php

namespace Extensions\ventacart\Services\VentaCart;

use Extensions\ventacart\Models\VentaCartSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class VentaCartStoreDeleter
{
    private const TABLES = [
        'ventacart_api_logs',
        'ventacart_sync_logs',
        'ventacart_order_status_map',
        'ventacart_status_placements',
        'ventacart_orders',
        'ventacart_listings',
        'ventacart_product_links',
        'ventacart_product_groups',
        'ventacart_categories',
        'ventacart_brands',
    ];

    public function delete(VentaCartSetting $store): array
    {
        return DB::transaction(function () use ($store) {
            $removed = [];
            if (Schema::hasTable('ventacart_order_products')) {
                $orderIds = DB::table('ventacart_orders')->where('ventacart_setting_id', $store->id)->pluck('id');
                $removed['ventacart_order_products'] = $orderIds->isEmpty() ? 0
                    : DB::table('ventacart_order_products')->whereIn('ventacart_order_id', $orderIds)->delete();
            }
            if (Schema::hasTable('ventacart_product_group_products')) {
                $groupIds = DB::table('ventacart_product_groups')->where('ventacart_setting_id', $store->id)->pluck('id');
                $removed['ventacart_product_group_products'] = $groupIds->isEmpty() ? 0
                    : DB::table('ventacart_product_group_products')->whereIn('ventacart_product_group_id', $groupIds)->delete();
            }
            foreach (self::TABLES as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'ventacart_setting_id')) {
                    continue;
                }
                $removed[$table] = DB::table($table)->where('ventacart_setting_id', $store->id)->delete();
            }
            if (Schema::hasTable('scheduled_jobs')) {
                $removed['scheduled_jobs'] = DB::table('scheduled_jobs')
                    ->where('integration', 'ventacart')->where('store_id', $store->id)->delete();
            }
            DB::table('ventacart_settings')->where('id', $store->id)->delete();

            return $removed;
        });
    }
}
