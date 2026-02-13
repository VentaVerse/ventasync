<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            ['key' => 'manage_options', 'label' => 'Manage Options'],
            ['key' => 'manage_settings', 'label' => 'Manage Settings'],
            ['key' => 'manage_shopee', 'label' => 'Manage Shopee'],
            ['key' => 'manage_lazada', 'label' => 'Manage Lazada'],
            ['key' => 'manage_users', 'label' => 'Manage Users'],
            ['key' => 'manage_user_groups', 'label' => 'Manage User Groups'],
            // Backwards compatible umbrella
            ['key' => 'manage_users_groups', 'label' => 'Manage Users & Groups'],
        ];

        foreach ($rows as $r) {
            DB::table('permissions')->updateOrInsert(['key' => $r['key']], $r);
        }
    }

    public function down(): void
    {
        // no-op
    }
};
