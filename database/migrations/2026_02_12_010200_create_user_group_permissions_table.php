<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('user_group_permissions')) {
            Schema::create('user_group_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('user_group_id');
                $table->unsignedBigInteger('permission_id');

                $table->primary(['user_group_id','permission_id']);
                $table->index('permission_id');
            });
        }

        // Ensure default groups exist
        DB::table('user_groups')->updateOrInsert(['name' => 'Administrator'], ['name' => 'Administrator', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('user_groups')->updateOrInsert(['name' => 'Staff'], ['name' => 'Staff', 'created_at' => now(), 'updated_at' => now()]);

        $adminId = DB::table('user_groups')->where('name','Administrator')->value('id');
        if ($adminId) {
            $permIds = DB::table('permissions')->pluck('id')->all();
            foreach ($permIds as $pid) {
                DB::table('user_group_permissions')->updateOrInsert([
                    'user_group_id' => $adminId,
                    'permission_id' => $pid,
                ], []);
            }
        }
    }

    public function down(): void
    {
        // no auto-drop
    }
};
