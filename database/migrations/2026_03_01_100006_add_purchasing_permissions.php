<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            'manage_purchasing' => ['label' => 'Purchasing', 'description' => 'Full access to all purchasing features'],
            'manage_vendors' => ['label' => 'Manage Vendors', 'description' => 'Access vendor management'],
            'manage_purchase_orders' => ['label' => 'Manage Purchase Orders', 'description' => 'Access purchase order management'],
        ];

        $hasLabel = Schema::hasColumn('permissions', 'label');
        $hasDescription = Schema::hasColumn('permissions', 'description');

        foreach ($permissions as $key => $meta) {
            $row = ['key' => $key];
            if ($hasLabel) {
                $row['label'] = $meta['label'];
            }
            if ($hasDescription) {
                $row['description'] = $meta['description'];
            }
            DB::table('permissions')->insertOrIgnore($row);
        }

        $adminGroupId = DB::table('user_groups')->where('name', 'Administrator')->value('id');

        if ($adminGroupId) {
            $permIds = DB::table('permissions')->whereIn('key', array_keys($permissions))->pluck('id');

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
