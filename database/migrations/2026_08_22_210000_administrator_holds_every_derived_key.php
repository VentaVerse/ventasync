<?php

use App\Services\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keep the administrators ahead of the catalogue as it grows.
 *
 * The catalogue is derived, so it changes whenever controllers do - and it
 * just did: the mobile and partner API was excluded on the reasoning that
 * those routes belong to a token's scope rather than a person's group, which
 * was wrong. They carry auth:sanctum, which resolves the token's OWNING USER,
 * and check that user's group.
 *
 * The cost of that mistake was quiet. Nine old keys stayed the only gate on
 * those routes while the editor stopped offering any of them, so a group
 * created in between could not be granted mobile access at all. Existing
 * groups kept theirs and nothing said a word.
 *
 * Idempotent and safe to re-run: it grants the group that is meant to reach
 * everything whatever the catalogue currently derives, which is the same rule
 * ExtensionManager::syncPermissions already applies to a new extension.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('user_groups')) {
            return;
        }

        $catalogue = app(PermissionCatalogue::class);
        $ids = DB::table('permissions')->pluck('id', 'key')->all();

        foreach ($catalogue->keys() as $key) {
            if (! isset($ids[$key])) {
                $ids[$key] = DB::table('permissions')->insertGetId(['key' => $key]);
            }
        }

        $adminId = DB::table('user_groups')
            ->whereRaw('LOWER(name) = ?', ['administrator'])
            ->value('id');

        if (! $adminId) {
            return;
        }

        $held = DB::table('user_group_permissions')
            ->where('user_group_id', $adminId)
            ->pluck('permission_id')
            ->all();

        $rows = [];

        foreach ($catalogue->keys() as $key) {
            if (isset($ids[$key]) && ! in_array($ids[$key], $held, true)) {
                $rows[] = ['user_group_id' => $adminId, 'permission_id' => $ids[$key]];
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('user_group_permissions')->insert($chunk);
        }
    }

    /**
     * Nothing. This only ever grants the administrators what they are meant
     * to have, and taking it back on a rollback would lock them out of
     * whatever the catalogue had grown to cover.
     */
    public function down(): void
    {
    }
};
