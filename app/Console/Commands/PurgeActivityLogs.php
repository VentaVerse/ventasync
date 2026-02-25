<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Setting;
use Illuminate\Console\Command;

class PurgeActivityLogs extends Command
{
    protected $signature = 'activity-log:purge {--days= : Override retention days from settings}';
    protected $description = 'Delete activity log entries older than the configured retention period';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: (Setting::singleton()->activity_log_retention_days ?? 90));

        $count = ActivityLog::where('created_at', '<', now()->subDays($days))->delete();

        $this->info("Deleted {$count} activity log entries older than {$days} days.");

        return self::SUCCESS;
    }
}
