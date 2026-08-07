<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed the `view_orders` permission row.
 *
 * `orders.show` has been gated `perm:view_orders|manage_orders` since the
 * Phase 4.5 view/manage tier split (see `routes/web.php:266`), and the core
 * order detail page (order-detail redesign, 2026-08-07) reads
 * `hasPermission('view_orders')` to decide whether to render its read-only
 * tier. `view_orders` has also been declared in config/permissions.php
 * since before that split, which should mean the boot-time core sync
 * (`ExtensionServiceProvider::boot()` -> `ExtensionManager::syncCore
 * Permissions()`) creates this row automatically - but that sync is gated
 * on a 24h cache flag keyed only by the declared key list, so a flag left
 * over from before a `permissions` table reset (or before this key existed)
 * can silently skip the sync indefinitely. Confirmed missing on the test
 * schema by the order-detail redesign's review round. This migration makes
 * the row deterministic instead of depending on that cache.
 *
 * Deliberately additive only, same idiom as every other permission-seeding
 * migration (see e.g. 2026_02_24_000300_add_tiktok_permission.php): insert
 * the key if it is not already there, nothing else. No group - not even
 * Administrator - is granted this permission here. The row existing only
 * makes it selectable in the User Groups UI; nobody's access changes until
 * an administrator explicitly grants it to a group. Zero live behaviour
 * change on its own.
 */
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

    /**
     * Only remove the row if nothing depends on it - deleting a permission
     * a group was actually granted would silently strip that group's
     * access, which is a bigger surprise than leaving an unused row.
     */
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
