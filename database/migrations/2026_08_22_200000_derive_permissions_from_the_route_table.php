<?php

use App\Services\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Move the permission list off hand-written manifests and onto the routes.
 *
 * The old list came from extension.json files plus config/permissions.php.
 * Nothing reconciled it, so it drifted: four keys survived a removed messages
 * feature that no sync could reach, because syncing only ever inserted. And
 * because a route had to REMEMBER to declare `perm:`, twenty authenticated
 * routes carried no permission at all.
 *
 * Deriving from the route table fixes both directions at once. A controller
 * that exists has a key; a controller that is gone does not.
 *
 * ADDITIVE. Every group keeps the rows it already had and gains the new keys
 * equivalent to them, translated from the route table rather than a
 * hand-written mapping. Nobody loses access here. Retiring the old keys is a
 * separate, later step taken once the changeover has been watched in
 * practice - a migration that grants and revokes in the same breath has no
 * safe half-way point.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('user_groups')) {
            return;
        }

        $catalog = app(PermissionCatalogue::class);

        $ids = DB::table('permissions')->pluck('id', 'key')->all();

        foreach ($catalog->keys() as $key) {
            if (! isset($ids[$key])) {
                $ids[$key] = DB::table('permissions')->insertGetId(['key' => $key]);
            }
        }

        /* ADMINISTRATOR HOLDS EVERY KEY, INCLUDING THE ONES NOTHING GATED.
         *
         * Twenty-four areas had no `perm:` middleware at all - some ungated,
         * some checking inside the controller instead - so no old grant
         * translates into them and the derivation alone would leave the
         * administrators locked out of automations, order printing, the
         * channel workspace and reorder suggestions the moment the new
         * middleware turns on. The group that is meant to reach everything
         * is granted everything, which is the same rule
         * ExtensionManager::syncPermissions already applies to a new
         * extension's keys. */
        $adminId = DB::table('user_groups')->whereRaw('LOWER(name) = ?', ['administrator'])->value('id');

        if ($adminId) {
            $held = DB::table('user_group_permissions')->where('user_group_id', $adminId)
                ->pluck('permission_id')->all();

            $rows = [];

            foreach ($catalog->keys() as $key) {
                if (isset($ids[$key]) && ! in_array($ids[$key], $held, true)) {
                    $rows[] = ['user_group_id' => $adminId, 'permission_id' => $ids[$key]];
                }
            }

            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('user_group_permissions')->insert($chunk);
            }
        }

        $map = $this->derivedMap($catalog);

        foreach (DB::table('user_groups')->pluck('id') as $groupId) {
            $held = DB::table('user_group_permissions as ugp')
                ->join('permissions as p', 'p.id', '=', 'ugp.permission_id')
                ->where('ugp.user_group_id', $groupId)
                ->pluck('p.key')
                ->all();

            $wanted = [];

            foreach ($held as $old) {
                foreach ($map[$old] ?? [] as $new) {
                    if (isset($ids[$new])) {
                        $wanted[$ids[$new]] = true;
                    }
                }
            }

            if ($wanted === []) {
                continue;
            }

            $already = DB::table('user_group_permissions')
                ->where('user_group_id', $groupId)
                ->pluck('permission_id')
                ->all();

            $rows = [];

            foreach (array_diff(array_keys($wanted), $already) as $permissionId) {
                $rows[] = ['user_group_id' => $groupId, 'permission_id' => $permissionId];
            }

            if ($rows !== []) {
                DB::table('user_group_permissions')->insert($rows);
            }
        }
    }

    /**
     * old key => [new keys], read off the route table.
     *
     * Tier is never elevated: an old view_ grant yields view_ keys only.
     * Three write routes accept a view-tier key today - POSTs that are
     * semantically reads - and carrying those forward as manage grants would
     * quietly widen someone's access during a migration.
     *
     * @return array<string, array<int, string>>
     */
    private function derivedMap(PermissionCatalogue $catalog): array
    {
        $map = [];

        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            $action = $route->getActionName();

            if (! str_contains($action, '@')) {
                continue;
            }

            [$class] = explode('@', $action);
            $new = $catalog->keyForController($class);

            if (! $new) {
                continue;
            }

            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'perm:')) {
                    continue;
                }

                foreach (preg_split('/[,|]/', substr($middleware, 5)) as $old) {
                    $old = trim($old);

                    if ($old !== '') {
                        $tier = str_starts_with($old, 'manage_') ? 'manage' : 'view';
                        $map[$old][$tier . '_' . $new] = true;
                    }
                }
            }
        }

        return array_map(fn ($set) => array_keys($set), $map);
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        // Only the derived keys. Anything the catalog does not name was
        // here before this migration and stays.
        $keys = app(PermissionCatalogue::class)->keys();
        $ids = DB::table('permissions')->whereIn('key', $keys)->pluck('id')->all();

        if ($ids !== []) {
            DB::table('user_group_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }
    }
};
