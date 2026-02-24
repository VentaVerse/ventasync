<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->updateOrInsert(
            ['key' => 'manage_opencart_orders'],
            ['key' => 'manage_opencart_orders', 'label' => 'OpenCart Orders']
        );

        $adminId = DB::table('user_groups')->where('name', 'Administrator')->value('id');
        if (!$adminId) {
            return;
        }

        $permId = DB::table('permissions')->where('key', 'manage_opencart_orders')->value('id');
        if ($permId) {
            DB::table('user_group_permissions')->updateOrInsert([
                'user_group_id' => $adminId,
                'permission_id' => $permId,
            ], []);
        }
    }

    public function down(): void
    {
        $permId = DB::table('permissions')->where('key', 'manage_opencart_orders')->value('id');
        if ($permId) {
            DB::table('user_group_permissions')->where('permission_id', $permId)->delete();
        }

        DB::table('permissions')->where('key', 'manage_opencart_orders')->delete();
    }
};

