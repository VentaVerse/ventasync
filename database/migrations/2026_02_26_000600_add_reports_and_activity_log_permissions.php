<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            [
                'key'         => 'manage_reports',
                'label'       => 'Reports',
                'description' => 'View order, product, category, fee, and inventory reports',
            ],
            [
                'key'         => 'manage_activity_log',
                'label'       => 'Activity Log',
                'description' => 'View and clear the activity log',
            ],
        ];

        $hasLabel = Schema::hasColumn('permissions', 'label');
        $hasDescription = Schema::hasColumn('permissions', 'description');

        $newIds = [];
        foreach ($permissions as $perm) {
            if (!DB::table('permissions')->where('key', $perm['key'])->exists()) {
                $row = ['key' => $perm['key']];
                if ($hasLabel) {
                    $row['label'] = $perm['label'];
                }
                if ($hasDescription) {
                    $row['description'] = $perm['description'];
                }
                $newIds[] = DB::table('permissions')->insertGetId($row);
            }
        }

        $adminGroup = DB::table('user_groups')->where('name', 'Administrator')->first();
        if ($adminGroup && !empty($newIds)) {
            foreach ($newIds as $pid) {
                DB::table('user_group_permissions')->insertOrIgnore([
                    'user_group_id' => $adminGroup->id,
                    'permission_id' => $pid,
                ]);
            }
        }

        $reportsPerm = DB::table('permissions')->where('key', 'manage_reports')->first();
        $activityPerm = DB::table('permissions')->where('key', 'manage_activity_log')->first();

        if ($reportsPerm) {
            $ordersPerm = DB::table('permissions')->where('key', 'manage_orders')->first();
            if ($ordersPerm) {
                $groupIds = DB::table('user_group_permissions')
                    ->where('permission_id', $ordersPerm->id)
                    ->pluck('user_group_id');
                foreach ($groupIds as $gid) {
                    DB::table('user_group_permissions')->insertOrIgnore([
                        'user_group_id' => $gid,
                        'permission_id' => $reportsPerm->id,
                    ]);
                }
            }
        }

        if ($activityPerm) {
            $settingsPerm = DB::table('permissions')->where('key', 'manage_settings')->first();
            if ($settingsPerm) {
                $groupIds = DB::table('user_group_permissions')
                    ->where('permission_id', $settingsPerm->id)
                    ->pluck('user_group_id');
                foreach ($groupIds as $gid) {
                    DB::table('user_group_permissions')->insertOrIgnore([
                        'user_group_id' => $gid,
                        'permission_id' => $activityPerm->id,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $keys = ['manage_reports', 'manage_activity_log'];
        $ids = DB::table('permissions')->whereIn('key', $keys)->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('user_group_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }
    }
};
