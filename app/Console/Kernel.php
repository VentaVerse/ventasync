<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('activity-log:purge')->daily();

        // Refresh signed license tokens daily at 03:00. License server is
        // contacted only for rows whose last_validated_at is older than 6
        // days, bounding traffic to roughly one refresh per license per week.
        $schedule->command('agila:license:refresh')->dailyAt('03:00');
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
