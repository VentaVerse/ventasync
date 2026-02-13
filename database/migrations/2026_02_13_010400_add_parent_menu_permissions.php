<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            ['key' => 'manage_catalog', 'label' => 'Catalog'],
            ['key' => 'manage_marketplace_api', 'label' => 'Marketplace API'],
            ['key' => 'manage_settings', 'label' => 'Settings'],
        ];

        foreach ($rows as $r) {
            DB::table('permissions')->updateOrInsert(['key' => $r['key']], $r);
        }

        $adminId = DB::table('user_groups')->where('name', 'Administrator')->value('id');
        if (!$adminId) {
            return;
        }

        $permIds = DB::table('permissions')->pluck('id')->all();
        foreach ($permIds as $pid) {
            DB::table('user_group_permissions')->updateOrInsert([
                'user_group_id' => $adminId,
                'permission_id' => $pid,
            ], []);
        }
    }

    public function down(): void
    {
        // no-op
    }
};
