<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The channel dashboards moved onto their own routes, so they derive their
 * own keys ({channel}/dashboard). This carries every group's existing
 * channel access onto the new keys.
 *
 * WITHOUT THIS, EVERY EXISTING GROUP LOSES ITS CHANNEL MENU. The card that
 * puts a channel in the nav now asks view_{channel}/dashboard, and the
 * dashboard's door asks the same; a group holding only the legacy
 * view_{channel} would pass neither. Tier is never elevated: a view grant
 * becomes the view key, a manage grant the manage key.
 *
 * permissions:sync-catalogue cannot do this part, because its translation
 * is derived from which routes an old key gated - and the old dashboard
 * route gated itself in code, invisibly to the route table. That
 * invisibility is the defect the whole change removes.
 */
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

                // Every group holding the legacy channel key, plus the
                // Administrator group by name - it must hold every derived
                // key whether or not the legacy rows survived earlier prunes.
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
