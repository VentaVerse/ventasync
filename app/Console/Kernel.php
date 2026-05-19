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
                    $args = is_array($job->options) ? $this->optionsToArgs($job->options) : [];

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
    protected function optionsToArgs(array $options): array
    {
        $args = [];
        foreach ($options as $key => $value) {
            if ($value === null || $value === '' || $value === false) {
                continue;
            }
            $flag = '--' . str_replace('_', '-', $key);
            // Boolean flags must be passed under a NUMERIC key so
            // Laravel's Schedule::compileParameters() emits them as
            // bare `--flag` instead of `--flag=1`. Symfony rejects the
            // latter for VALUE_NONE options ("does not accept a value").
            if ($value === true) {
                $args[] = $flag;
            } else {
                $args[$flag] = (string) $value;
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
