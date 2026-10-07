<?php

namespace App\Console\Commands;

use App\Extensions\ExtensionManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PermissionsReconcile extends Command
{
    protected $signature = 'permissions:reconcile {--prune : Delete the orphaned permissions and the grants pointing at them}';

    protected $description = 'Find permissions that no manifest or config declares any more';

    public function handle(ExtensionManager $manager): int
    {
        $orphans = $manager->orphanedPermissionKeys();

        if ($orphans === []) {
            $this->info('Every permission is still declared by config or a manifest. Nothing to reconcile.');

            return self::SUCCESS;
        }

        $this->line(count($orphans) . ' permission(s) are declared nowhere on disk:');
        $this->newLine();

        foreach ($orphans as $key) {
            $holders = DB::table('user_group_permissions as ugp')
                ->join('permissions as p', 'p.id', '=', 'ugp.permission_id')
                ->join('user_groups as g', 'g.id', '=', 'ugp.user_group_id')
                ->where('p.key', $key)
                ->pluck('g.name')
                ->all();

            $this->line(sprintf(
                '  %-34s %s',
                $key,
                $holders === [] ? 'held by no group' : 'held by ' . implode(', ', $holders)
            ));
        }

        $this->newLine();

        if (! $this->option('prune')) {
            $this->line('Run with --prune to remove them. Nothing has been changed.');

            return self::SUCCESS;
        }

        $removed = $manager->pruneOrphanedPermissions();

        $this->info('Removed ' . $removed . ' permission(s) and every grant that pointed at them.');

        return self::SUCCESS;
    }
}
