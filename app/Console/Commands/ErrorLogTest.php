<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ErrorLogTest extends Command
{
    protected $signature = 'errorlog:test {--message= : Custom message to log}';
    protected $description = 'Generate a non-fatal PHP warning to validate the error.log capture.';

    public function handle(): int
    {
        $message = $this->option('message') ?: 'ERP error.log test warning (artisan)';
        @trigger_error($message, E_USER_WARNING);

        $this->info('Test warning triggered: ' . $message);

        return self::SUCCESS;
    }
}
