<?php

namespace App\Console\Commands;

use App\Models\Admin\Permission;
use App\Services\PermissionCatalogue;
use Illuminate\Console\Command;

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
