<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('petty_cash_user_roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->enum('role', ['admin', 'staff'])->default('staff');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        // Clean up old permissions
        $oldKeys = [
            'pettycash_add_credits',
            'pettycash_view_all_staff',
            'pettycash_manage_all',
            'view_own_petty_cash',
            'manage_petty_cash',
        ];

        $oldPermIds = DB::table('permissions')->whereIn('key', $oldKeys)->pluck('id')->all();

        if (!empty($oldPermIds)) {
            DB::table('user_group_permissions')->whereIn('permission_id', $oldPermIds)->delete();
            DB::table('permissions')->whereIn('id', $oldPermIds)->delete();
        }

        // Insert new permissions
        $newPerms = [
            ['key' => 'view_petty_cash', 'label' => 'View Petty Cash', 'description' => ''],
            ['key' => 'manage_petty_cash_settings', 'label' => 'Manage Petty Cash Settings', 'description' => ''],
        ];

        $adminGroupId = DB::table('user_groups')->where('name', 'Administrator')->value('id');
        $staffGroupId = DB::table('user_groups')->where('name', 'Staff')->value('id');

        foreach ($newPerms as $perm) {
            $existing = DB::table('permissions')->where('key', $perm['key'])->first();

            if (!$existing) {
                $permId = DB::table('permissions')->insertGetId($perm);
            } else {
                $permId = $existing->id;
            }

            // Grant to admin group
            if ($adminGroupId) {
                DB::table('user_group_permissions')->updateOrInsert([
                    'user_group_id' => $adminGroupId,
                    'permission_id' => $permId,
                ]);
            }

            // Grant view_petty_cash to staff group
            if ($staffGroupId && $perm['key'] === 'view_petty_cash') {
                DB::table('user_group_permissions')->updateOrInsert([
                    'user_group_id' => $staffGroupId,
                    'permission_id' => $permId,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('petty_cash_user_roles');

        // Remove new permissions
        $newKeys = ['view_petty_cash', 'manage_petty_cash_settings'];
        $permIds = DB::table('permissions')->whereIn('key', $newKeys)->pluck('id')->all();

        if (!empty($permIds)) {
            DB::table('user_group_permissions')->whereIn('permission_id', $permIds)->delete();
            DB::table('permissions')->whereIn('id', $permIds)->delete();
        }
    }
};
