<?php

namespace App\Console;

use App\Models\ScheduledJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Schema;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        // Core / framework jobs that are never operator-editable.
        $schedule->command('activity-log:purge')->daily();
        // Sync-run and API-call logs. Without this they grow forever: this
        // box reached 310,120 venta_sync_logs rows before anything trimmed
        // them. See App\Support\LogRetention for what is swept and what is
        // deliberately left alone.
        $schedule->command('logs:purge')->daily();

        // Universal admin scheduler: every enabled row in scheduled_jobs
        // is registered with Laravel's scheduler using its stored cron
        // expression. The Automations tab on each integration page edits
        // these rows; one OS-level cron (`* * * * * php artisan
        // schedule:run`) drives everything. Wrapped in try/catch so a
        // fresh install or a broken DB still boots the framework.
        try {
            if (Schema::hasTable('scheduled_jobs')) {
                foreach (ScheduledJob::query()->where('enabled', true)->get() as $job) {
                    $command = (string) $job->command;
                    $args = $this->optionsToArgs($job->commandArguments());

                    $event = $schedule->command($command, $args)
                        ->cron($job->cron_expression ?: '*/15 * * * *')
                        ->withoutOverlapping()
                        ->name("automations:job:{$job->id}");

                    // Stamp last_run on completion so the UI can show
                    // when each job last fired without scraping logs.
                    $jobId = (int) $job->id;
                    $event->onSuccess(function () use ($jobId) {
                        ScheduledJob::where('id', $jobId)->update([
                            'last_run_at' => now(),
                            'last_run_ok' => true,
                        ]);
                    });
                    $event->onFailure(function () use ($jobId) {
                        ScheduledJob::where('id', $jobId)->update([
                            'last_run_at' => now(),
                            'last_run_ok' => false,
                        ]);
                    });
                }
            }
        } catch (\Throwable $e) {
            // Logging the error is fine; never let scheduler boot crash.
            \Illuminate\Support\Facades\Log::warning('ScheduledJob registration failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Convert a job's options array into the artisan flag/argument form
     * accepted by `$schedule->command($cmd, $args)`. Known keys map to
     * documented CLI options; everything else is passed as `--key=value`.
     */
    protected function optionsToArgs(array $arguments): array
    {
        // ScheduledJob::commandArguments() is the one place options become
        // flags; this only reshapes booleans for the scheduler, which wants
        // a bare `--flag` under a NUMERIC key so Schedule::compileParameters()
        // emits it without `=1` (Symfony rejects that for VALUE_NONE options).
        $args = [];
        foreach ($arguments as $flag => $value) {
            if ($value === true) {
                $args[] = $flag;
            } else {
                $args[$flag] = $value;
            }
        }
        return $args;
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
