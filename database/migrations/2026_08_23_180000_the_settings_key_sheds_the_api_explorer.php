<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase B of the one-vocabulary plan: the channel bundle keys split.
 *
 * {channel}/overview was one key over four jobs. Its routes now derive
 * {channel}/settings (credentials, tokens, status maps, logging) and
 * {channel}/api_explorer (requests sent to the marketplace as the shop).
 * Petty Cash's mis-named overview - which was always the ledger - becomes
 * pettycash/ledger.
 *
 * GRANTS CARRY TO SETTINGS AND THE LEDGER, NEVER TO THE EXPLORER. Holding
 * the old bundle included the explorer only because the bundle was
 * indivisible; carrying it forward would silently hand every settings
 * holder the ability to act as the shop. permissions:vocabulary-report
 * names the groups affected, so the explorer is granted back deliberately
 * or not at all. (The Administrator group holds every derived key by the
 * boot sync, explorer included.)
 */
return new class extends Migration
{
    private const RENAMES = [
        // old key                      => new key, same tier both sides
        'view_lazada/overview' => 'view_lazada/settings',
        'manage_lazada/overview' => 'manage_lazada/settings',
        'view_shopee/overview' => 'view_shopee/settings',
        'manage_shopee/overview' => 'manage_shopee/settings',
        'view_tiktok/overview' => 'view_tiktok/settings',
        'manage_tiktok/overview' => 'manage_tiktok/settings',
        'view_venta/overview' => 'view_venta/settings',
        'manage_venta/overview' => 'manage_venta/settings',
        'view_opencart/overview' => 'view_opencart/settings',
        'manage_opencart/overview' => 'manage_opencart/settings',
        'view_pedallion/overview' => 'view_pedallion/settings',
        'manage_pedallion/overview' => 'manage_pedallion/settings',
        'view_shopify/overview' => 'view_shopify/settings',
        'manage_shopify/overview' => 'manage_shopify/settings',
        'view_pettycash/overview' => 'view_pettycash/ledger',
        'manage_pettycash/overview' => 'manage_pettycash/ledger',
    ];

    public function up(): void
    {
        foreach (self::RENAMES as $old => $new) {
            $oldId = DB::table('permissions')->where('key', $old)->value('id');

            if ($oldId === null) {
                continue;
            }

            $newId = DB::table('permissions')->where('key', $new)->value('id');

            if ($newId === null) {
                // Nothing holds the new name yet: the row itself renames,
                // grants riding along untouched.
                DB::table('permissions')->where('id', $oldId)->update(['key' => $new]);

                continue;
            }

            // Both exist (the boot sync ran first): move the grants, drop
            // the old row.
            foreach (DB::table('user_group_permissions')->where('permission_id', $oldId)->pluck('user_group_id') as $groupId) {
                DB::table('user_group_permissions')->insertOrIgnore([
                    'user_group_id' => $groupId,
                    'permission_id' => $newId,
                ]);
            }

            DB::table('user_group_permissions')->where('permission_id', $oldId)->delete();
            DB::table('permissions')->where('id', $oldId)->delete();
        }
    }

    public function down(): void
    {
        foreach (array_flip(self::RENAMES) as $old => $new) {
            DB::table('permissions')->where('key', $old)->update(['key' => $new]);
        }
    }
};
