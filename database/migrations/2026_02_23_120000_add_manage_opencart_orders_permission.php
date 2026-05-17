<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // label/description are guarded because the columns are dropped by
        // 2026_04_09_drop_label_description_from_permissions_table. When this
        // migration runs late (community-then-Plus upgrade), the drop has
        // already happened; check the schema and only include columns that
        // still exist.
        $row = ['key' => 'manage_opencart_orders'];
        if (Schema::hasColumn('permissions', 'label')) {
            $row['label'] = 'OpenCart Orders';
        }

        DB::table('permissions')->updateOrInsert(
            ['key' => 'manage_opencart_orders'],
            $row
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

