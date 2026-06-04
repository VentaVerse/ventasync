<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remove the orphaned `view_options` / `manage_options` permissions.
 *
 * The standalone Catalog → Options page was removed (its nav item,
 * routes, controller and views are gone). Product variations are gated
 * by `manage_products`, so these two keys now gate nothing a user can
 * reach. They were also dropped from config/permissions.php, so the
 * boot-time core-permission sync (additive only) will not re-create
 * them.
 *
 * user_group_permissions has no FK cascade, so grant rows are deleted
 * explicitly before the permission rows.
 */
return new class extends Migration
{
    private array $keys = ['view_options', 'manage_options'];

    public function up(): void
    {
        $ids = DB::table('permissions')->whereIn('key', $this->keys)->pluck('id');

        if ($ids->isNotEmpty()) {
            DB::table('user_group_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }
    }

    /**
     * Re-create the permission keys so the schema is reversible.
     * Grants are NOT restored (that data is gone); the boot sync will
     * re-grant them to Administrator only if the keys are also added
     * back to config/permissions.php.
     */
    public function down(): void
    {
        foreach ($this->keys as $key) {
            DB::table('permissions')->updateOrInsert(['key' => $key], ['key' => $key]);
        }
    }
};
