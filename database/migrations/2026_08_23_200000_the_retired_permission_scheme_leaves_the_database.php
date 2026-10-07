<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TRANSLATIONS = [
        'manage_activity_log' => ['manage_audit/activity_log'],
        'manage_api_clients' => ['manage_settings/api_client'],
        'manage_audit' => ['manage_audit/activity_log'],
        'manage_catalog' => ['manage_api/catalog', 'manage_catalog/category', 'manage_catalog/manufacturer'],
        'manage_categories' => ['manage_api/catalog', 'manage_catalog/category', 'manage_catalog/manufacturer'],
        'manage_error_log' => ['manage_settings/error_log'],
        'manage_lazada' => ['manage_lazada/brand', 'manage_lazada/category', 'manage_lazada/category_attribute', 'manage_lazada/settings', 'manage_lazada/product', 'manage_lazada/product_group'],
        'manage_lazada_orders' => ['manage_lazada/order'],
        'manage_manufacturers' => ['manage_api/catalog', 'manage_catalog/category', 'manage_catalog/manufacturer'],
        'manage_opencart' => ['manage_opencart/settings', 'manage_opencart/product_group'],
        'manage_opencart_orders' => ['manage_opencart/order'],
        'manage_order_statuses' => ['manage_settings/order_status'],
        'manage_orders' => ['manage_api/order', 'manage_sales/order'],
        'manage_pedallion' => ['manage_pedallion/category', 'manage_pedallion/settings', 'manage_pedallion/product', 'manage_pedallion/product_group'],
        'manage_pedallion_orders' => ['manage_pedallion/order'],
        'manage_petty_cash_settings' => ['manage_pettycash/settings'],
        'manage_petty_cash_transactions' => ['manage_pettycash/ledger'],
        'manage_products' => ['manage_api/catalog', 'manage_api/product', 'manage_catalog/category', 'manage_catalog/manufacturer', 'manage_catalog/product', 'manage_catalog/product_image', 'manage_catalog/product_option', 'manage_purchasing/product_vendor'],
        'manage_purchase_orders' => ['manage_purchasing/purchase_order', 'manage_purchasing/reorder'],
        'manage_purchasing' => ['manage_purchasing/purchase_order', 'manage_purchasing/reorder', 'manage_purchasing/vendor'],
        'manage_reports' => ['manage_reports/report'],
        'manage_reviews' => ['manage_opencart/review'],
        'manage_shopee' => ['manage_shopee/category', 'manage_shopee/category_attribute', 'manage_shopee/logistics', 'manage_shopee/settings', 'manage_shopee/product', 'manage_shopee/product_group'],
        'manage_shopee_orders' => ['manage_shopee/order'],
        'manage_shopify' => ['manage_shopify/settings'],
        'manage_shopify_orders' => ['manage_shopify/order'],
        'manage_tiktok' => ['manage_tiktok/settings', 'manage_tiktok/product_group'],
        'manage_tiktok_orders' => ['manage_tiktok/order'],
        'manage_user_groups' => ['manage_settings/user_group'],
        'manage_users' => ['manage_settings/user'],
        'manage_vendors' => ['manage_purchasing/vendor'],
        'manage_venta' => ['manage_venta/settings', 'manage_venta/product_group', 'manage_venta/review'],
        'manage_venta_orders' => ['manage_venta/api_venta_order_api', 'manage_venta/order'],
        'manage_warehouse_inventory' => ['manage_warehousing/warehouse_inventory'],
        'manage_warehouse_locations' => ['manage_warehousing/warehouse'],
        'manage_warehouse_transfers' => ['manage_warehousing/warehouse_transfer'],
        'manage_warehousing' => ['manage_warehousing/warehouse', 'manage_warehousing/warehouse_inventory', 'manage_warehousing/warehouse_transfer'],
        'manage_website_settings' => ['manage_settings/currency', 'manage_settings/extension', 'manage_settings/setting', 'manage_settings/settings_hub'],
        'view_activity_log' => ['view_audit/activity_log'],
        'view_api_clients' => ['view_settings/api_client'],
        'view_audit' => ['view_audit/activity_log'],
        'view_categories' => ['view_api/catalog', 'view_catalog/category', 'view_catalog/manufacturer'],
        'view_error_log' => ['view_settings/error_log'],
        'view_lazada' => ['view_lazada/brand', 'view_lazada/category', 'view_lazada/category_attribute', 'view_lazada/settings', 'view_lazada/product', 'view_lazada/product_group'],
        'view_lazada_orders' => ['view_lazada/order'],
        'view_manufacturers' => ['view_api/catalog', 'view_catalog/category', 'view_catalog/manufacturer'],
        'view_opencart' => ['view_opencart/settings', 'view_opencart/product_group'],
        'view_opencart_orders' => ['view_opencart/order'],
        'view_order_statuses' => ['view_settings/order_status'],
        'view_orders' => ['view_sales/order'],
        'view_pedallion' => ['view_pedallion/category', 'view_pedallion/settings', 'view_pedallion/product', 'view_pedallion/product_group'],
        'view_pedallion_orders' => ['view_pedallion/order'],
        'view_petty_cash' => ['view_pettycash/ledger', 'view_pettycash/settings'],
        'view_petty_cash_settings' => ['view_pettycash/settings'],
        'view_petty_cash_transactions' => ['view_pettycash/ledger'],
        'view_products' => ['view_api/catalog', 'view_catalog/category', 'view_catalog/manufacturer', 'view_catalog/product', 'view_catalog/product_image'],
        'view_purchase_orders' => ['view_purchasing/purchase_order'],
        'view_purchasing' => ['view_purchasing/purchase_order', 'view_purchasing/vendor'],
        'view_reports' => ['view_reports/report'],
        'view_reviews' => ['view_opencart/review'],
        'view_shopee' => ['view_shopee/category', 'view_shopee/category_attribute', 'view_shopee/logistics', 'view_shopee/settings', 'view_shopee/product', 'view_shopee/product_group'],
        'view_shopee_orders' => ['view_shopee/order'],
        'view_shopify' => ['view_shopify/settings'],
        'view_shopify_orders' => ['view_shopify/order'],
        'view_tiktok' => ['view_tiktok/settings', 'view_tiktok/product_group'],
        'view_tiktok_orders' => ['view_tiktok/order'],
        'view_user_groups' => ['view_settings/user_group'],
        'view_users' => ['view_settings/user'],
        'view_vendors' => ['view_purchasing/vendor'],
        'view_venta' => ['view_venta/settings', 'view_venta/product_group'],
        'view_venta_orders' => ['view_venta/order'],
        'view_warehouse_inventory' => ['view_warehousing/warehouse_inventory'],
        'view_warehouse_locations' => ['view_warehousing/warehouse'],
        'view_warehouse_transfers' => ['view_warehousing/warehouse_transfer'],
        'view_warehousing' => ['view_warehousing/warehouse', 'view_warehousing/warehouse_inventory', 'view_warehousing/warehouse_transfer'],
        'view_website_settings' => ['view_settings/currency', 'view_settings/extension', 'view_settings/setting', 'view_settings/settings_hub'],
    ];

    public function up(): void
    {
        DB::transaction(function () {
            $derivedIds = [];

            $idFor = function (string $key) use (&$derivedIds): int {
                if (! isset($derivedIds[$key])) {
                    $derivedIds[$key] = DB::table('permissions')->where('key', $key)->value('id')
                        ?? DB::table('permissions')->insertGetId(['key' => $key]);
                }

                return $derivedIds[$key];
            };

            foreach (self::TRANSLATIONS as $legacy => $derived) {
                $legacyId = DB::table('permissions')->where('key', $legacy)->value('id');

                if ($legacyId === null) {
                    continue;
                }

                $holders = DB::table('user_group_permissions')
                    ->where('permission_id', $legacyId)
                    ->pluck('user_group_id');

                foreach ($holders as $groupId) {
                    foreach ($derived as $key) {
                        DB::table('user_group_permissions')->insertOrIgnore([
                            'user_group_id' => $groupId,
                            'permission_id' => $idFor($key),
                        ]);
                    }
                }
            }

            $legacyIds = DB::table('permissions')
                ->where('key', 'not like', '%/%')
                ->pluck('id');

            DB::table('user_group_permissions')->whereIn('permission_id', $legacyIds)->delete();
            DB::table('permissions')->whereIn('id', $legacyIds)->delete();
        });
    }

    public function down(): void
    {
    }
};
