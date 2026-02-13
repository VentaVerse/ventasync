<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Keep Administrator group fully privileged as new permissions are added.
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
