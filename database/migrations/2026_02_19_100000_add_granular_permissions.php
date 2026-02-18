<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            // Catalog children
            ['key' => 'manage_products', 'label' => 'Products'],
            ['key' => 'manage_categories', 'label' => 'Categories'],
            ['key' => 'manage_manufacturers', 'label' => 'Manufacturers'],
            ['key' => 'manage_options', 'label' => 'Options'],

            // Sales children
            ['key' => 'manage_orders', 'label' => 'Orders'],
            ['key' => 'manage_order_statuses', 'label' => 'Order Statuses'],

            // Marketplace API children
            ['key' => 'manage_shopee', 'label' => 'Shopee'],
            ['key' => 'manage_lazada', 'label' => 'Lazada'],
            ['key' => 'manage_opencart', 'label' => 'OpenCart'],

            // Settings children
            ['key' => 'manage_users', 'label' => 'Users'],
            ['key' => 'manage_user_groups', 'label' => 'User Groups'],
            ['key' => 'manage_website_settings', 'label' => 'Website Settings'],
            ['key' => 'manage_error_log', 'label' => 'Error Log'],

            // Parent permissions (keep)
            ['key' => 'manage_catalog', 'label' => 'Catalog (All)'],
            ['key' => 'manage_sales', 'label' => 'Sales (All)'],
            ['key' => 'manage_marketplace_api', 'label' => 'Marketplace API (All)'],
            ['key' => 'manage_settings', 'label' => 'Settings (All)'],
        ];

        foreach ($rows as $r) {
            DB::table('permissions')->updateOrInsert(['key' => $r['key']], $r);
        }

        // Grant all new permissions to Administrator group
        $adminId = DB::table('user_groups')->where('name', 'Administrator')->value('id');
        if ($adminId) {
            $permIds = DB::table('permissions')->pluck('id')->all();
            foreach ($permIds as $pid) {
                DB::table('user_group_permissions')->updateOrInsert([
                    'user_group_id' => $adminId,
                    'permission_id' => $pid,
                ], []);
            }
        }

        // Clean up legacy permission that is no longer needed
        // manage_users_groups is replaced by manage_users + manage_user_groups
        // Don't delete it — just leave it in place for safety
    }

    public function down(): void
    {
        // no-op
    }
};
