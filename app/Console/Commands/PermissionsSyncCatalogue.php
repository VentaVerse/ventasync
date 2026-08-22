<?php

namespace App\Console\Commands;

use App\Models\Admin\Permission;
use App\Services\PermissionCatalogue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Bring the permissions table in line with the routes, and carry the existing
 * grants across.
 *
 * The catalogue derives what CAN be permitted. This writes that down, and -
 * once, on the changeover - translates every group's current grants into the
 * new keys so nobody arrives on Monday having lost a menu.
 *
 * THE TRANSLATION IS DERIVED, NOT HAND-WRITTEN. For each old key we ask the
 * route table which routes it gated, resolve those routes' controllers, and
 * grant the new keys those controllers answer to. A hand-kept mapping table
 * would be one more thing to drift, which is the failure this whole change
 * exists to end.
 *
 * TIER IS NEVER ELEVATED. An old view_ grant becomes view_ keys only. Three
 * write routes currently accept a view-tier key - two Lazada previews and a
 * bulk AWB fetch, all POSTs that are semantically reads - and carrying that
 * forward as a manage grant would quietly widen someone's access during a
 * migration. Those routes declare their own tier instead; see
 * EnsureControllerPermission.
 */
class PermissionsSyncCatalogue extends Command
{
    protected $signature = 'permissions:sync-catalogue
                            {--dry-run : Report what would change and write nothing}
                            {--no-migrate : Sync the keys but do not translate existing grants}';

    protected $description = 'Derive the permission list from the routes and migrate existing grants onto it';

    public function handle(PermissionCatalogue $catalogue): int
    {
        $wanted = $catalogue->keys();
        sort($wanted);

        $existing = Permission::pluck('id', 'key')->all();

        $toAdd = array_values(array_diff($wanted, array_keys($existing)));
        $legacy = array_values(array_diff(array_keys($existing), $wanted));

        $this->line('Catalogue: ' . count($catalogue->all()) . ' areas, ' . count($wanted) . ' keys');
        $this->line('  new keys to add:      ' . count($toAdd));
        $this->line('  keys not in catalogue: ' . count($legacy) . ' (the old scheme)');

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->line('Dry run. Nothing written.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($toAdd, $catalogue) {
            foreach ($toAdd as $key) {
                Permission::firstOrCreate(['key' => $key]);
            }

            if (! $this->option('no-migrate')) {
                $this->translateGrants($catalogue);
            }
        });

        $this->newLine();
        $this->info('Synced. ' . Permission::count() . ' permission rows.');

        return self::SUCCESS;
    }

    /**
     * Give every group the new keys equivalent to what it already held.
     *
     * Additive on purpose: the old rows stay attached until the changeover is
     * verified and someone runs the prune. A migration that removed access in
     * the same step as adding it has no safe half-way point.
     */
    private function translateGrants(PermissionCatalogue $catalogue): void
    {
        $map = $this->derivedMap($catalogue);
        $ids = Permission::pluck('id', 'key')->all();

        $this->newLine();
        $this->line('Translating existing grants:');

        foreach (DB::table('user_groups')->get() as $group) {
            $held = DB::table('user_group_permissions as ugp')
                ->join('permissions as p', 'p.id', '=', 'ugp.permission_id')
                ->where('ugp.user_group_id', $group->id)
                ->pluck('p.key')
                ->all();

            $grant = [];

            foreach ($held as $old) {
                foreach ($map[$old] ?? [] as $new) {
                    if (isset($ids[$new])) {
                        $grant[$ids[$new]] = true;
                    }
                }
            }

            $added = 0;

            foreach (array_keys($grant) as $permissionId) {
                $exists = DB::table('user_group_permissions')
                    ->where('user_group_id', $group->id)
                    ->where('permission_id', $permissionId)
                    ->exists();

                if (! $exists) {
                    DB::table('user_group_permissions')->insert([
                        'user_group_id' => $group->id,
                        'permission_id' => $permissionId,
                    ]);
                    $added++;
                }
            }

            $this->line(sprintf('  %-22s %d held -> %d new key(s), %d added',
                $group->name, count($held), count($grant), $added));
        }
    }

    /**
     * old key => [new keys], read straight off the route table.
     *
     * @return array<string, array<int, string>>
     */
    public function derivedMap(PermissionCatalogue $catalogue): array
    {
        $map = [];

        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();

            if (! str_contains($action, '@')) {
                continue;
            }

            [$class] = explode('@', $action);
            $new = $catalogue->keyForController($class);

            if (! $new) {
                continue;
            }

            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'perm:')) {
                    continue;
                }

                foreach (preg_split('/[,|]/', substr($middleware, 5)) as $old) {
                    $old = trim($old);

                    if ($old === '') {
                        continue;
                    }

                    // Same tier out as in. Never elevate on a migration.
                    $tier = str_starts_with($old, 'manage_') ? 'manage' : 'view';
                    $map[$old][$tier . '_' . $new] = true;
                }
            }
        }

        return array_map(fn ($set) => array_keys($set), $map);
    }
}
