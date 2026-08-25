<?php

namespace App\Console\Commands;

use App\Models\Admin\Permission;
use App\Services\PermissionCatalogue;
use Illuminate\Console\Command;

/**
 * Bring the permissions table in line with the routes, by hand.
 *
 * The boot sync (ExtensionServiceProvider) does the same thing on a cache
 * fingerprint of the key list; this is the explicit version for when you
 * want to see the numbers, or to converge a database the booted process
 * has not touched.
 *
 * The grant-translation machinery this command carried during the
 * changeover is gone with the retired scheme: the Phase D migration
 * translated and pruned in one transaction, and a restored old backup
 * converges the same way - by running its migrations forward.
 */
class PermissionsSyncCatalogue extends Command
{
    protected $signature = 'permissions:sync-catalogue
                            {--dry-run : Report what would change and write nothing}';

    protected $description = 'Derive the permission list from the routes and write any missing rows';

    public function handle(PermissionCatalogue $catalog): int
    {
        $wanted = $catalog->keys();
        sort($wanted);

        $existing = Permission::pluck('id', 'key')->all();

        $toAdd = array_values(array_diff($wanted, array_keys($existing)));
        $stray = array_values(array_diff(array_keys($existing), $wanted));

        $this->line('Catalog: ' . count($catalog->all()) . ' areas, ' . count($wanted) . ' keys');
        $this->line('  missing rows to add: ' . count($toAdd));
        $this->line('  rows no route derives: ' . count($stray) . ' (permissions:reconcile audits these)');

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->line('Dry run. Nothing written.');

            return self::SUCCESS;
        }

        // A key that did not exist a moment ago is granted to the
        // Administrator group as it is created, the rule extension keys
        // already follow: an administrator must never lose a screen because
        // a route grew its own key (live fix 2026-08-26, order payments).
        $adminGroupId = \Illuminate\Support\Facades\DB::table('user_groups')->where('name', 'Administrator')->value('id');
        foreach ($toAdd as $key) {
            $row = Permission::firstOrCreate(['key' => $key]);
            if ($adminGroupId && !\Illuminate\Support\Facades\DB::table('user_group_permissions')->where('user_group_id', $adminGroupId)->where('permission_id', $row->id)->exists()) {
                \Illuminate\Support\Facades\DB::table('user_group_permissions')->insert(['user_group_id' => $adminGroupId, 'permission_id' => $row->id]);
            }
        }

        $this->newLine();
        $this->info('Synced. ' . Permission::count() . ' permission rows.');

        return self::SUCCESS;
    }
}
