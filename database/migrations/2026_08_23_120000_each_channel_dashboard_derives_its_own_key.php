<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CHANNELS = [
        'lazada', 'shopee', 'tiktok', 'opencart', 'venta', 'pedallion', 'shopify',
    ];

    public function up(): void
    {
        foreach (self::CHANNELS as $channel) {
            foreach (['view', 'manage'] as $tier) {
                $new = $tier . '_' . $channel . '/dashboard';
                $old = $tier . '_' . $channel;

                $newId = DB::table('permissions')->where('key', $new)->value('id')
                    ?? DB::table('permissions')->insertGetId(['key' => $new]);

                $groups = DB::table('user_group_permissions')
                    ->join('permissions', 'permissions.id', '=', 'user_group_permissions.permission_id')
                    ->where('permissions.key', $old)
                    ->pluck('user_group_permissions.user_group_id')
                    ->merge(DB::table('user_groups')->where('name', 'Administrator')->pluck('id'))
                    ->unique();

                foreach ($groups as $groupId) {
                    DB::table('user_group_permissions')->insertOrIgnore([
                        'user_group_id' => $groupId,
                        'permission_id' => $newId,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $keys = [];

        foreach (self::CHANNELS as $channel) {
            $keys[] = 'view_' . $channel . '/dashboard';
            $keys[] = 'manage_' . $channel . '/dashboard';
        }

        $ids = DB::table('permissions')->whereIn('key', $keys)->pluck('id');
        DB::table('user_group_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
