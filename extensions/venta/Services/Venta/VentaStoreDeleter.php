<?php

namespace Extensions\venta\Services\Venta;

use Extensions\venta\Models\VentaSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class VentaStoreDeleter
{
    private const TABLES = [
        'venta_api_logs',
        'venta_sync_logs',
        'venta_order_status_map',
        'venta_status_placements',
        'venta_orders',
        'venta_listings',
        'venta_product_links',
        'venta_product_groups',
        'venta_categories',
        'venta_brands',
    ];

    public function delete(VentaSetting $store): array
    {
        return DB::transaction(function () use ($store) {
            $removed = [];
            if (Schema::hasTable('venta_order_products')) {
                $orderIds = DB::table('venta_orders')->where('venta_setting_id', $store->id)->pluck('id');
                $removed['venta_order_products'] = $orderIds->isEmpty() ? 0
                    : DB::table('venta_order_products')->whereIn('venta_order_id', $orderIds)->delete();
            }
            if (Schema::hasTable('venta_product_group_products')) {
                $groupIds = DB::table('venta_product_groups')->where('venta_setting_id', $store->id)->pluck('id');
                $removed['venta_product_group_products'] = $groupIds->isEmpty() ? 0
                    : DB::table('venta_product_group_products')->whereIn('venta_product_group_id', $groupIds)->delete();
            }
            foreach (self::TABLES as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'venta_setting_id')) {
                    continue;
                }
                $removed[$table] = DB::table($table)->where('venta_setting_id', $store->id)->delete();
            }
            if (Schema::hasTable('scheduled_jobs')) {
                $removed['scheduled_jobs'] = DB::table('scheduled_jobs')
                    ->where('integration', 'venta')->where('store_id', $store->id)->delete();
            }
            DB::table('venta_settings')->where('id', $store->id)->delete();

            return $removed;
        });
    }
}
