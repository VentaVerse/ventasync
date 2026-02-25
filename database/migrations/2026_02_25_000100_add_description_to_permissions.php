<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->string('description')->nullable()->after('label');
        });

        $descriptions = [
            'manage_catalog'          => 'Full access to all catalog features',
            'manage_products'         => 'View, create, edit, and delete products',
            'manage_categories'       => 'View, create, edit, and delete categories',
            'manage_manufacturers'    => 'View, create, edit, and delete manufacturers',
            'manage_options'          => 'View, create, edit, and delete product options',
            'manage_sales'            => 'Full access to all sales features',
            'manage_orders'           => 'View and manage customer orders',
            'manage_order_statuses'   => 'Create and edit order status definitions',
            'manage_marketplace_api'  => 'Full access to all marketplace integrations',
            'manage_shopee'           => 'Shopee store settings and product sync',
            'manage_shopee_orders'    => 'View and sync orders from Shopee',
            'manage_lazada'           => 'Lazada store settings and product sync',
            'manage_lazada_orders'    => 'View and sync orders from Lazada',
            'manage_tiktok'           => 'TikTok Shop settings and product sync',
            'manage_opencart'         => 'OpenCart store integration and product sync',
            'manage_opencart_orders'  => 'View and sync orders from OpenCart',
            'manage_settings'         => 'Full access to all system settings',
            'manage_users'            => 'View, create, edit, and deactivate users',
            'manage_user_groups'      => 'Manage user groups and permissions',
            'manage_website_settings' => 'Edit global website configuration',
            'manage_error_log'        => 'View and clear application error log',
        ];

        foreach ($descriptions as $key => $desc) {
            DB::table('permissions')->where('key', $key)->update(['description' => $desc]);
        }
    }

    public function down(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
