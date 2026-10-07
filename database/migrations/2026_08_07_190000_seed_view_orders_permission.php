<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('permissions')->where('key', 'view_orders')->exists();
        if (!$exists) {
            DB::table('permissions')->insert([
                'key' => 'view_orders',
            ]);
        }
    }

    public function down(): void
    {
        $perm = DB::table('permissions')->where('key', 'view_orders')->first();
        if (!$perm) {
            return;
        }

        $granted = DB::table('user_group_permissions')->where('permission_id', $perm->id)->exists();
        if ($granted) {
            return;
        }

        DB::table('permissions')->where('id', $perm->id)->delete();
    }
};
