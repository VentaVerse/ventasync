<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Register the extension if not already registered
        if (Schema::hasTable('extensions') && !DB::table('extensions')->where('id', 'pettycash')->exists()) {
            DB::table('extensions')->insert([
                'id'          => 'pettycash',
                'name'        => 'Petty Cash',
                'version'     => '1.1.0',
                'description' => 'Staff petty cash tracking',
                'author'      => 'Xenon ERP',
                'enabled'     => 1,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        // Ensure permissions exist
        $permissions = [
            ['key' => 'view_petty_cash', 'label' => 'Petty Cash', 'description' => 'See the Petty Cash nav group'],
            ['key' => 'view_petty_cash_transactions', 'label' => 'Petty Cash Transactions', 'description' => 'View and manage petty cash transactions'],
            ['key' => 'manage_petty_cash_settings', 'label' => 'Petty Cash Settings', 'description' => 'Access petty cash settings and role management'],
        ];

        foreach ($permissions as $p) {
            if (!DB::table('permissions')->where('key', $p['key'])->exists()) {
                DB::table('permissions')->insert($p);
            }
        }

        // Find group IDs by name
        $adminGroupId = DB::table('user_groups')->where('name', 'Administrator')->value('id');

        // Grant all 3 to admin group
        if ($adminGroupId) {
            foreach (['view_petty_cash', 'view_petty_cash_transactions', 'manage_petty_cash_settings'] as $key) {
                $permId = DB::table('permissions')->where('key', $key)->value('id');
                if ($permId) {
                    DB::table('user_group_permissions')->updateOrInsert(
                        ['user_group_id' => $adminGroupId, 'permission_id' => $permId]
                    );
                }
            }
        }

        // Clean up old permissions
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
