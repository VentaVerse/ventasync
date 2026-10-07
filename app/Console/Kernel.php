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
        $schedule->command('activity-log:purge')->daily();
        $schedule->command('logs:purge')->daily();

        try {
            if (Schema::hasTable('scheduled_jobs')) {
                foreach (ScheduledJob::query()->where('enabled', true)->get() as $job) {
                    $command = (string) $job->command;
                    $args = $this->optionsToArgs($job->commandArguments());

                    $event = $schedule->command($command, $args)
                        ->cron($job->cron_expression ?: '*/15 * * * *')
                        ->withoutOverlapping()
                        ->name("automations:job:{$job->id}");

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
            \Illuminate\Support\Facades\Log::warning('ScheduledJob registration failed', ['error' => $e->getMessage()]);
        }
    }

    protected function optionsToArgs(array $arguments): array
    {
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

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
