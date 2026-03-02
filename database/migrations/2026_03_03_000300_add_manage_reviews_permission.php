<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $perm = [
            'key'         => 'manage_reviews',
            'label'       => 'Reviews',
            'description' => 'View marketplace reviews and manage OpenCart review sync',
        ];

        if (DB::table('permissions')->where('key', $perm['key'])->exists()) {
            return;
        }

        $permId = DB::table('permissions')->insertGetId($perm);

        // Auto-assign to Administrator group
        $adminGroup = DB::table('user_groups')->where('name', 'Administrator')->first();
        if ($adminGroup) {
            DB::table('user_group_permissions')->insertOrIgnore([
                'user_group_id' => $adminGroup->id,
                'permission_id' => $permId,
            ]);
        }

        // Also assign to any group that has manage_marketplace_api
        $mpPerm = DB::table('permissions')->where('key', 'manage_marketplace_api')->first();
        if ($mpPerm) {
            $groupIds = DB::table('user_group_permissions')
                ->where('permission_id', $mpPerm->id)
                ->pluck('user_group_id');
            foreach ($groupIds as $gid) {
                DB::table('user_group_permissions')->insertOrIgnore([
                    'user_group_id' => $gid,
                    'permission_id' => $permId,
                ]);
            }
        }
    }

    public function down(): void
    {
        $perm = DB::table('permissions')->where('key', 'manage_reviews')->first();
        if ($perm) {
            DB::table('user_group_permissions')->where('permission_id', $perm->id)->delete();
            DB::table('permissions')->where('id', $perm->id)->delete();
        }
    }
};
