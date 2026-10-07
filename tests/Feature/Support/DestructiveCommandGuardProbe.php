<?php

require __DIR__.'/../../../vendor/autoload.php';

use Illuminate\Console\Prohibitable;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Database\Console\Migrations\MigrateCommand;
use Illuminate\Database\Console\Migrations\RefreshCommand;
use Illuminate\Database\Console\Migrations\ResetCommand;
use Illuminate\Database\Console\Migrations\RollbackCommand;
use Illuminate\Database\Console\WipeCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

$app = require __DIR__.'/../../../bootstrap/app.php';

$kernel = $app->make(ConsoleKernel::class);

$kernel->bootstrap();

echo 'CONFIG_DB='.config('database.connections.'.config('database.default').'.database').PHP_EOL;

$prohibited = static fn (string $class): bool => (bool) (new ReflectionProperty($class, 'prohibitedFromRunning'))->getValue();

echo 'FRESH_PROHIBITED='.($prohibited(FreshCommand::class) ? '1' : '0').PHP_EOL;
echo 'REFRESH_PROHIBITED='.($prohibited(RefreshCommand::class) ? '1' : '0').PHP_EOL;
echo 'RESET_PROHIBITED='.($prohibited(ResetCommand::class) ? '1' : '0').PHP_EOL;
echo 'ROLLBACK_PROHIBITED='.($prohibited(RollbackCommand::class) ? '1' : '0').PHP_EOL;
echo 'WIPE_PROHIBITED='.($prohibited(WipeCommand::class) ? '1' : '0').PHP_EOL;

$migrateTraits = class_uses(MigrateCommand::class) ?: [];
echo 'MIGRATE_USES_PROHIBITABLE='.(in_array(Prohibitable::class, $migrateTraits, true) ? '1' : '0').PHP_EOL;

$safeDatabase = getenv('GUARD_PROBE_SAFE_DATABASE');

if (! is_string($safeDatabase) || $safeDatabase === '' || ! str_ends_with($safeDatabase, '_test')) {
    echo 'MIGRATE_EXIT_CODE=SKIPPED_UNSAFE_TARGET'.PHP_EOL;
    exit(0);
}

Config::set('database.connections.mysql.database', $safeDatabase);
DB::purge('mysql');

$exitCode = Artisan::call('migrate', ['--force' => true]);
echo 'MIGRATE_EXIT_CODE='.$exitCode.PHP_EOL;
echo 'MIGRATE_OUTPUT='.trim(str_replace(["\r", "\n"], ' ', Artisan::output())).PHP_EOL;
