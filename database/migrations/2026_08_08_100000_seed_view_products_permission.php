<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed the `view_products` permission row.
 *
 * `view_products` has been declared in config/permissions.php (gating
 * products.index and the other read routes in routes/catalog.php via
 * `perm:view_products|manage_products`) for a while, which should mean the
 * boot-time core sync (`ExtensionServiceProvider::boot()` ->
 * `ExtensionManager::syncCorePermissions()`) creates this row automatically
 * - but that sync is gated on a 24h cache flag keyed only by the declared
 * key list, so a flag left over from before a `permissions` table reset (or
 * before this key existed) can silently skip the sync indefinitely. Same
 * live gap already tracked and fixed the same way for `view_orders`
 * (2026_08_07_190000_seed_view_orders_permission.php); confirmed missing on
 * the test schema by the catalogue redesign's final review round
 * (2026-08-08, final-review.md, requirement 10). This migration makes the
 * row deterministic instead of depending on that cache.
 *
 * Deliberately additive only, same idiom as every other permission-seeding
 * migration: insert the key if it is not already there, nothing else. No
 * group - not even Administrator - is granted this permission here. The row
 * existing only makes it selectable in the User Groups UI; nobody's access
 * changes until an administrator explicitly grants it to a group. Zero live
 * behaviour change on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('permissions')->where('key', 'view_products')->exists();
        if (!$exists) {
            DB::table('permissions')->insert([
                'key' => 'view_products',
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
        $perm = DB::table('permissions')->where('key', 'view_products')->first();
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
