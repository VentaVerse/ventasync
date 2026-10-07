<?php

namespace App\Console\Commands;

use App\Services\ActivityLogger;
use App\Support\LogRetention;
use Illuminate\Console\Command;

class PurgeLogs extends Command
{
    protected $signature = 'logs:purge {--days= : Trim everything to this many days instead of each table\'s own}';

    protected $description = 'Delete sync and API log rows older than the configured retention';

    public function handle(): int
    {
        $removed = LogRetention::purge(
            $this->option('days') !== null ? (int) $this->option('days') : null,
        );

        $total = array_sum($removed);

        foreach (\App\Plans\FileRetention::purge() as $what => $count) {
            $this->line(sprintf('%-24s %s', $what, number_format($count) . ' files'));
        }

        foreach ($removed as $table => $count) {
            $this->line(sprintf('%-24s %s', $table, number_format($count)));
        }

        $this->info($total > 0 ? number_format($total) . ' log rows removed.' : 'Nothing older than the retention.');

        if ($total > 0) {
            ActivityLogger::log('purged', 'Setting', null, 'Sync and API logs (' . number_format($total) . ' rows)');
        }

        return self::SUCCESS;
    }
}
