<?php

/**
 * Standalone probe used by DestructiveCommandGuardTest. This is a plain PHP script, not a
 * PHPUnit test case, and it is never run through phpunit/tests/bootstrap.php.
 *
 * Why: tests/bootstrap.php and Tests\TestCase both enforce their OWN, separate `_test`-suffix
 * guard (see tests/Feature/DatabaseGuardPreventsWipeTest.php) and would refuse to run before
 * AppServiceProvider::boot() ever executed. That guard protects the PHPUnit run itself; it
 * says nothing about a bare `php artisan migrate:fresh` run from a shell, which is exactly
 * what wiped the sandbox on 2026-08-05. To exercise the AppServiceProvider guard
 * (app/Providers/AppServiceProvider.php:41-42) the way a real deploy would hit it, this
 * script boots the application the same way the `artisan` CLI entrypoint does: it requires
 * bootstrap/app.php directly and bootstraps the console kernel, running every
 * ServiceProvider::boot() including AppServiceProvider's.
 *
 * DestructiveCommandGuardTest spawns this in a genuinely separate PHP process (Symfony
 * Process) with DB_DATABASE overridden to a name that does not end in `_test`, so the guard
 * has to decide the "shaped like production" case for real, not something the test merely
 * asserts about in isolation.
 *
 * Emits one KEY=VALUE line per check to stdout; the test parses and asserts each line
 * individually so a failure names exactly which check broke.
 */

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

/** @var ConsoleKernel $kernel */
$kernel = $app->make(ConsoleKernel::class);

// Runs every registered ServiceProvider::boot(), including AppServiceProvider, exactly as
// `php artisan <anything>` would. This is the real code path, not a re-implementation of it.
$kernel->bootstrap();

echo 'CONFIG_DB='.config('database.connections.'.config('database.default').'.database').PHP_EOL;

$prohibited = static fn (string $class): bool => (bool) (new ReflectionProperty($class, 'prohibitedFromRunning'))->getValue();

echo 'FRESH_PROHIBITED='.($prohibited(FreshCommand::class) ? '1' : '0').PHP_EOL;
echo 'REFRESH_PROHIBITED='.($prohibited(RefreshCommand::class) ? '1' : '0').PHP_EOL;
echo 'RESET_PROHIBITED='.($prohibited(ResetCommand::class) ? '1' : '0').PHP_EOL;
echo 'ROLLBACK_PROHIBITED='.($prohibited(RollbackCommand::class) ? '1' : '0').PHP_EOL;
echo 'WIPE_PROHIBITED='.($prohibited(WipeCommand::class) ? '1' : '0').PHP_EOL;

// MigrateCommand does not use the Prohibitable trait at all (confirmed by the Task 0
// reviewer), so DB::prohibitDestructiveCommands() has no lever to pull against it -- there is
// no isProhibited() gate on this class, full stop. Assert that structurally.
$migrateTraits = class_uses(MigrateCommand::class) ?: [];
echo 'MIGRATE_USES_PROHIBITABLE='.(in_array(Prohibitable::class, $migrateTraits, true) ? '1' : '0').PHP_EOL;

// Also prove it functionally: a plain `migrate` must still complete normally in THIS SAME
// process, where the guard above is latched active. Point the connection at a real,
// already-migrated `_test` schema first (never the fake name above, never live data) --
// the caller passes it in and this script refuses to proceed without one shaped like a test
// database, so a misconfigured caller can't turn this into a real migrate run.
$safeDatabase = getenv('GUARD_PROBE_SAFE_DATABASE');

if (! is_string($safeDatabase) || $safeDatabase === '' || ! str_ends_with($safeDatabase, '_test')) {
    echo 'MIGRATE_EXIT_CODE=SKIPPED_UNSAFE_TARGET'.PHP_EOL;
    exit(0);
}

Config::set('database.connections.mysql.database', $safeDatabase);
// Boot already attempted (and silently caught, per AppServiceProvider's own try/catch) a
// query against the fake database above, which caches a Connection bound to that fake name.
// Purge it so the migrate call below actually uses $safeDatabase.
DB::purge('mysql');

$exitCode = Artisan::call('migrate', ['--force' => true]);
echo 'MIGRATE_EXIT_CODE='.$exitCode.PHP_EOL;
echo 'MIGRATE_OUTPUT='.trim(str_replace(["\r", "\n"], ' ', Artisan::output())).PHP_EOL;
