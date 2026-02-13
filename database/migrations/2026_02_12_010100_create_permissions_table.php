<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->string('label');
            });
        }

        // Seed base permissions (idempotent)
        $rows = [
            ['key' => 'manage_products', 'label' => 'Manage Products'],
            ['key' => 'manage_manufacturers', 'label' => 'Manage Manufacturers'],
            ['key' => 'manage_categories', 'label' => 'Manage Categories'],
            // Keep legacy key for backwards compatibility
            ['key' => 'manage_users_groups', 'label' => 'Manage Users & Groups'],

            ['key' => 'manage_users', 'label' => 'Manage Users'],
            ['key' => 'manage_user_groups', 'label' => 'Manage User Groups'],
            ['key' => 'manage_options', 'label' => 'Manage Options'],
            ['key' => 'manage_settings', 'label' => 'Manage Settings'],
            ['key' => 'manage_shopee', 'label' => 'Manage Shopee'],
            ['key' => 'manage_lazada', 'label' => 'Manage Lazada'],
        ];

        foreach ($rows as $r) {
            DB::table('permissions')->updateOrInsert(['key' => $r['key']], $r);
        }
    }

    public function down(): void
    {
        // no auto-drop
    }
};
