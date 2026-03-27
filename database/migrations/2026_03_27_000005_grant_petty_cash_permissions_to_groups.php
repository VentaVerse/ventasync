<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Ensure all petty cash permissions exist
        $permissions = [
            ['key' => 'view_petty_cash', 'label' => 'Petty Cash', 'description' => 'See the Petty Cash nav group'],
            ['key' => 'view_petty_cash_transactions', 'label' => 'Petty Cash Transactions', 'description' => 'View and manage petty cash transactions'],
            ['key' => 'manage_petty_cash_settings', 'label' => 'Petty Cash Settings', 'description' => 'Access petty cash settings and role management'],
        ];

        foreach ($permissions as $p) {
            $exists = DB::table('permissions')->where('key', $p['key'])->exists();
            if (!$exists) {
                DB::table('permissions')->insert($p);
            }
        }

        // Grant all 3 to admin group (group 1)
        $adminPerms = ['view_petty_cash', 'view_petty_cash_transactions', 'manage_petty_cash_settings'];
        foreach ($adminPerms as $key) {
            $permId = DB::table('permissions')->where('key', $key)->value('id');
            if ($permId && !DB::table('user_group_permissions')->where('user_group_id', 1)->where('permission_id', $permId)->exists()) {
                DB::table('user_group_permissions')->insert(['user_group_id' => 1, 'permission_id' => $permId]);
            }
        }

        // Grant view_petty_cash + view_petty_cash_transactions to staff group (group 2) if it exists
        if (DB::table('user_groups')->where('id', 2)->exists()) {
            $staffPerms = ['view_petty_cash', 'view_petty_cash_transactions'];
            foreach ($staffPerms as $key) {
                $permId = DB::table('permissions')->where('key', $key)->value('id');
                if ($permId && !DB::table('user_group_permissions')->where('user_group_id', 2)->where('permission_id', $permId)->exists()) {
                    DB::table('user_group_permissions')->insert(['user_group_id' => 2, 'permission_id' => $permId]);
                }
            }
        }

        // Clean up old permissions that are no longer used
        $oldKeys = ['manage_petty_cash', 'view_own_petty_cash', 'pettycash_add_credits', 'pettycash_view_all_staff', 'pettycash_manage_all'];
        $oldIds = DB::table('permissions')->whereIn('key', $oldKeys)->pluck('id')->toArray();
        if (!empty($oldIds)) {
            DB::table('user_group_permissions')->whereIn('permission_id', $oldIds)->delete();
            DB::table('permissions')->whereIn('id', $oldIds)->delete();
        }
    }

    public function down(): void
    {
        // No rollback needed
    }
};
