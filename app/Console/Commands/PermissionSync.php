<?php

namespace App\Console\Commands;

use App\Extensions\ExtensionManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PermissionSync extends Command
{
    protected $signature = 'permission:sync {--force : Bust the boot cache so the next request also re-syncs}';
    protected $description = 'Sync core permissions from config/permissions.php and every installed extension manifest into the permissions table';

    public function handle(ExtensionManager $manager): int
    {
        $before = DB::table('permissions')->count();

        $manager->syncCorePermissions();

        foreach ($manager->getEnabledIds() as $id) {
            $manager->syncPermissions($id);
        }

        if ($this->option('force')) {
            Cache::flush();
        }

        $after = DB::table('permissions')->count();
        $added = $after - $before;

        $this->info("permissions table: {$before} → {$after} rows ({$added} inserted).");
        return self::SUCCESS;
    }
}
