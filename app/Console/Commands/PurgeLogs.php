<?php

namespace App\Console\Commands;

use App\Services\ActivityLogger;
use App\Support\LogRetention;
use Illuminate\Console\Command;

/**
 * Trims the sync-run and API-call logs to their retention. Scheduled daily
 * beside activity-log:purge, and run on demand by the Purge now button in
 * Settings, Website.
 */
class PurgeLogs extends Command
{
    protected $signature = 'logs:purge {--sync-days= : Override the sync retention} {--api-days= : Override the API retention}';

    protected $description = 'Delete sync and API log rows older than the configured retention';

    public function handle(): int
    {
        $removed = LogRetention::purge(
            $this->option('sync-days') !== null ? (int) $this->option('sync-days') : null,
            $this->option('api-days') !== null ? (int) $this->option('api-days') : null,
        );

        $total = array_sum($removed);

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
