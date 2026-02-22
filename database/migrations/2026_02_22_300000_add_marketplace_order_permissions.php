<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            ['key' => 'manage_shopee_orders', 'label' => 'Shopee Orders'],
            ['key' => 'manage_lazada_orders', 'label' => 'Lazada Orders'],
        ];

        foreach ($rows as $r) {
            DB::table('permissions')->updateOrInsert(['key' => $r['key']], $r);
        }

        // Grant to Administrator group
        $adminId = DB::table('user_groups')->where('name', 'Administrator')->value('id');
        if ($adminId) {
            $permIds = DB::table('permissions')
                ->whereIn('key', ['manage_shopee_orders', 'manage_lazada_orders'])
                ->pluck('id')
                ->all();

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
        $permIds = DB::table('permissions')
            ->whereIn('key', ['manage_shopee_orders', 'manage_lazada_orders'])
            ->pluck('id')
            ->all();

        DB::table('user_group_permissions')->whereIn('permission_id', $permIds)->delete();
        DB::table('permissions')->whereIn('key', ['manage_shopee_orders', 'manage_lazada_orders'])->delete();
    }
};
