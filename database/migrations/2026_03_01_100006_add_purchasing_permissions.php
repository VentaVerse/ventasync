<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = ['manage_purchasing', 'manage_vendors', 'manage_purchase_orders'];

        foreach ($permissions as $key) {
            DB::table('permissions')->insertOrIgnore([
                'key' => $key,
            ]);
        }

        // Grant to Administrator group (id = 1)
        $adminGroupId = DB::table('user_groups')->where('name', 'Administrator')->value('id');

        if ($adminGroupId) {
            $permIds = DB::table('permissions')->whereIn('key', $permissions)->pluck('id');

            foreach ($permIds as $permId) {
                DB::table('user_group_permissions')->insertOrIgnore([
                    'permission_id' => $permId,
                    'user_group_id' => $adminGroupId,
                ]);
            }
        }
    }

    public function down(): void
    {
        $permIds = DB::table('permissions')->whereIn('key', [
            'manage_purchasing', 'manage_vendors', 'manage_purchase_orders',
        ])->pluck('id');

        DB::table('user_group_permissions')->whereIn('permission_id', $permIds)->delete();
        DB::table('permissions')->whereIn('id', $permIds)->delete();
    }
};
