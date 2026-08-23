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

    public function handle(PermissionCatalogue $catalogue): int
    {
        $wanted = $catalogue->keys();
        sort($wanted);

        $existing = Permission::pluck('id', 'key')->all();

        $toAdd = array_values(array_diff($wanted, array_keys($existing)));
        $stray = array_values(array_diff(array_keys($existing), $wanted));

        $this->line('Catalogue: ' . count($catalogue->all()) . ' areas, ' . count($wanted) . ' keys');
        $this->line('  missing rows to add: ' . count($toAdd));
        $this->line('  rows no route derives: ' . count($stray) . ' (permissions:reconcile audits these)');

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->line('Dry run. Nothing written.');

            return self::SUCCESS;
        }

        foreach ($toAdd as $key) {
            Permission::firstOrCreate(['key' => $key]);
        }

        $this->newLine();
        $this->info('Synced. ' . Permission::count() . ' permission rows.');

        return self::SUCCESS;
    }
}
