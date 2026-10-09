<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ORDER_STATUSES = [
        [1, 'Pending', 1, 0],
        [2, 'Processing', 1, 0],
        [3, 'Shipped', 1, 0],
        [5, 'Canceled', 0, 0],
        [6, 'Denied', 0, 0],
        [7, 'Reversed', 0, 0],
        [8, 'Failed', 0, 0],
        [9, 'Delivered', 1, 1],
        [10, 'Completed', 1, 1],
        [11, 'Unpaid', 1, 0],
    ];

    private const SHOPEE_STATUSES = [
        'UNPAID' => 11, 'READY_TO_SHIP' => 1, 'PROCESSED' => 2, 'SHIPPED' => 3, 'COMPLETED' => 9, 'CANCELLED' => 5,
        'IN_CANCEL' => 1, 'RETRY_SHIP' => 3, 'TO_CONFIRM_RECEIVE' => 9, 'TO_RETURN' => 3, 'INVOICE_PENDING' => 1,
    ];

    private const LAZADA_STATUSES = [
        'unpaid' => 11, 'pending' => 1, 'packed' => 2, 'repacked' => 2, 'ready_to_ship' => 2, 'shipped' => 3,
        'lost_by_3pl' => 3, 'damaged_by_3pl' => 3, 'delivered' => 9, 'confirmed' => 10, 'canceled' => 5, 'cancelled' => 5,
        'failed_delivery' => 8,
    ];

    private const TIKTOK_STATUSES = [
        'UNPAID' => 11, 'ON_HOLD' => 1, 'AWAITING_SHIPMENT' => 1, 'AWAITING_COLLECTION' => 2, 'IN_TRANSIT' => 3,
        'DELIVERED' => 9, 'COMPLETED' => 10, 'CANCELLED' => 5,
    ];

    private const ADMINISTRATOR_PERMISSIONS = [
        'manage_api_v1/inventory',
        'manage_api_v1/product',
        'manage_api_v1/receivable',
        'manage_api/auth',
        'manage_api/catalog',
        'manage_api/device_token',
        'manage_api/marketplace_order',
        'manage_api/order',
        'manage_api/product',
        'manage_audit/activity_log',
        'manage_catalog/brand',
        'manage_catalog/category',
        'manage_catalog/product',
        'manage_catalog/product_image',
        'manage_catalog/product_variation',
        'manage_catalog/product_video',
        'manage_finance/petty_cash_settings',
        'manage_lazada/brand',
        'manage_lazada/category',
        'manage_lazada/category_attribute',
        'manage_lazada/dashboard',
        'manage_lazada/order',
        'manage_lazada/product',
        'manage_lazada/product_group',
        'manage_lazada/settings',
        'manage_opencart/dashboard',
        'manage_opencart/order',
        'manage_opencart/product_group',
        'manage_opencart/review',
        'manage_opencart/settings',
        'manage_pedallion/dashboard',
        'manage_purchasing/product_supplier',
        'manage_purchasing/purchase_order',
        'manage_purchasing/reorder',
        'manage_purchasing/supplier',
        'manage_reports/report',
        'manage_sales/order',
        'manage_sales/order_payment',
        'manage_settings/api_client',
        'manage_settings/currency',
        'manage_settings/error_log',
        'manage_settings/extension',
        'manage_settings/fulfilment',
        'manage_settings/order_status',
        'manage_settings/setting',
        'manage_settings/settings_hub',
        'manage_settings/user',
        'manage_settings/user_group',
        'manage_shopee/category',
        'manage_shopee/category_attribute',
        'manage_shopee/dashboard',
        'manage_shopee/logistics',
        'manage_shopee/order',
        'manage_shopee/product',
        'manage_shopee/product_group',
        'manage_shopee/settings',
        'manage_shopify/dashboard',
        'manage_tiktok/dashboard',
        'manage_ventacart/dashboard',
        'view_api_v1/customer',
        'view_api_v1/inventory',
        'view_api_v1/order',
        'view_api_v1/product',
        'view_api_v1/receivable',
        'view_api/auth',
        'view_api/catalog',
        'view_api/dashboard',
        'view_api/device_token',
        'view_api/marketplace_config',
        'view_api/marketplace_order',
        'view_api/order',
        'view_api/product',
        'view_catalog/brand',
        'view_catalog/category',
        'view_catalog/product',
        'view_catalog/product_image',
        'view_catalog/product_variation',
        'view_catalog/product_video',
        'view_finance/petty_cash_ledger',
        'view_finance/petty_cash_settings',
        'view_lazada/dashboard',
        'view_opencart/dashboard',
        'view_pedallion/dashboard',
        'view_sales/order',
        'view_sales/order_payment',
        'view_settings/api_client',
        'view_settings/currency',
        'view_settings/error_log',
        'view_settings/extension',
        'view_settings/fulfilment',
        'view_settings/order_status',
        'view_settings/setting',
        'view_settings/settings_hub',
        'view_settings/user',
        'view_settings/user_group',
        'view_shopee/dashboard',
        'view_shopify/dashboard',
        'view_tiktok/dashboard',
        'view_ventacart/api_venta_cart_order_api',
        'view_ventacart/dashboard',
    ];

    private const PETTY_CASH_CATEGORIES = ['Gas/Fuel', 'Shipping/Courier', 'Tools & Supplies', 'Office Supplies', 'Food/Meals', 'Transportation', 'Miscellaneous'];

    public function up(): void
    {
        $now = now();

        DB::table('user_groups')->insert([
            ['id' => 1, 'name' => 'Administrator', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'name' => 'Staff', 'created_at' => $now, 'updated_at' => $now],
        ]);

        foreach (self::ADMINISTRATOR_PERMISSIONS as $key) {
            $permission = DB::table('permissions')->insertGetId(['key' => $key]);
            DB::table('user_group_permissions')->insert(['user_group_id' => 1, 'permission_id' => $permission]);
        }

        DB::table('order_statuses')->insert(array_map(fn ($s) => [
            'order_status_id' => $s[0], 'language_id' => 1, 'name' => $s[1], 'subtract_stock' => $s[2], 'add_revenue' => $s[3],
        ], self::ORDER_STATUSES));

        foreach (['ch_shopee_order_status_map' => ['shopee_status', self::SHOPEE_STATUSES],
            'ch_lazada_order_status_map' => ['lazada_status', self::LAZADA_STATUSES],
            'ch_tiktok_order_status_map' => ['tiktok_status', self::TIKTOK_STATUSES]] as $table => [$column, $map]) {
            DB::table($table)->insert(array_map(fn ($status, $id) => [
                $column => $status, 'context' => 'order', 'order_status_id' => $id, 'created_at' => $now, 'updated_at' => $now,
            ], array_keys($map), $map));
        }

        DB::table('currencies')->insert([
            ['code' => 'PHP', 'name' => 'Philippine Peso', 'symbol' => '₱', 'exchange_rate' => 1, 'is_default' => true, 'status' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'exchange_rate' => 56.5, 'is_default' => false, 'status' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'CNY', 'name' => 'Chinese Yuan', 'symbol' => '¥', 'exchange_rate' => 7.8, 'is_default' => false, 'status' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::table('warehouses')->insert([
            'name' => 'Main Store', 'code' => 'STORE', 'is_default' => true, 'is_sellable' => true, 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);

        DB::table('petty_cash_categories')->insert(array_map(fn ($name, $i) => [
            'name' => $name, 'sort_order' => $i + 1, 'status' => true, 'created_at' => $now, 'updated_at' => $now,
        ], self::PETTY_CASH_CATEGORIES, array_keys(self::PETTY_CASH_CATEGORIES)));

        DB::table('scheduled_jobs')->insert([
            'command' => 'webhooks:process', 'display_name' => 'Process Channel Webhooks', 'extension' => 'core', 'store_id' => null,
            'cadence_value' => 1, 'cadence_unit' => 'minute', 'cron_expression' => '* * * * *', 'enabled' => true, 'options' => '[]',
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
    }
};
