<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Regression coverage for the DB::prohibitDestructiveCommands() guard added in
 * app/Providers/AppServiceProvider.php:41-42 (commit 6861d58c), which is a DIFFERENT guard
 * from the one tests/Feature/DatabaseGuardPreventsWipeTest.php covers.
 *
 * That other test proves tests/bootstrap.php + Tests\TestCase::setUp() stop PHPUnit's own
 * RefreshDatabase from wiping a misconfigured schema. It says nothing about a bare
 * `php artisan migrate:fresh` run from a shell -- which is exactly what wiped the sandbox
 * (44,172 orders) on 2026-08-05. That path never goes through PHPUnit at all, so this
 * guard was added directly in AppServiceProvider::boot(), read from `.env` on every request
 * and every Artisan invocation, the same as production.
 *
 * This process (running against ventasync_test, per phpunit.xml) is exactly where that
 * guard is INACTIVE, since ventasync_test ends in `_test`. A naive assertion made from
 * inside this process would never exercise the "shaped like production" branch. So this
 * test spawns a genuinely separate PHP process (see
 * tests/Feature/Support/DestructiveCommandGuardProbe.php) that boots the real application --
 * the same code path `php artisan <anything>` takes -- with DB_DATABASE overridden to a name
 * that does not end in `_test`, and reads back the framework's own bookkeeping: the static
 * `$prohibitedFromRunning` property that Illuminate\Console\Prohibitable exposes on each
 * guarded command class.
 */
class DestructiveCommandGuardTest extends TestCase
{
    public function test_destructive_commands_are_prohibited_against_a_non_test_database(): void
    {
        $result = $this->runProbe();

        $this->assertSame(
            '1',
            $result['FRESH_PROHIBITED'] ?? null,
            "migrate:fresh must be prohibited when DB_DATABASE does not end in '_test'.\n".$result['__raw']
        );
        $this->assertSame(
            '1',
            $result['REFRESH_PROHIBITED'] ?? null,
            "migrate:refresh must be prohibited when DB_DATABASE does not end in '_test'.\n".$result['__raw']
        );
        $this->assertSame(
            '1',
            $result['RESET_PROHIBITED'] ?? null,
            "migrate:reset must be prohibited when DB_DATABASE does not end in '_test'.\n".$result['__raw']
        );
        $this->assertSame(
            '1',
            $result['ROLLBACK_PROHIBITED'] ?? null,
            "migrate:rollback must be prohibited when DB_DATABASE does not end in '_test'.\n".$result['__raw']
        );
        $this->assertSame(
            '1',
            $result['WIPE_PROHIBITED'] ?? null,
            "db:wipe must be prohibited when DB_DATABASE does not end in '_test'.\n".$result['__raw']
        );
    }

    public function test_plain_migrate_is_not_prohibited_and_still_runs(): void
    {
        $result = $this->runProbe();

        $this->assertSame(
            '0',
            $result['MIGRATE_USES_PROHIBITABLE'] ?? null,
            "MigrateCommand must not gain the Prohibitable trait -- a real deploy's plain ".
            "`php artisan migrate` must never be blocked by this guard.\n".$result['__raw']
        );

        $this->assertSame(
            '0',
            $result['MIGRATE_EXIT_CODE'] ?? null,
            "`php artisan migrate` must exit 0 even while the guard above is latched active ".
            "in the same process (proving a real deploy is unaffected).\n".$result['__raw']
        );
    }

    /**
     * @return array<string, string>
     */
    private function runProbe(): array
    {
        // Sanity: this process must itself be pointed at a real `_test` schema before we
        // hand its name to the subprocess as the "safe to actually migrate" target.
        $realTestDatabase = (string) config('database.connections.'.config('database.default').'.database');
        $this->assertTrue(
            str_ends_with($realTestDatabase, '_test'),
            "Expected this test run's own database ('{$realTestDatabase}') to end in '_test' -- ".
            'if it does not, something is already misconfigured before this test even starts.'
        );

        $fakeProductionShapedDatabase = 'zzz_guard_probe_'.bin2hex(random_bytes(4));

        $probe = base_path('tests/Feature/Support/DestructiveCommandGuardProbe.php');

        $process = new Process(
            command: [PHP_BINARY, $probe],
            cwd: base_path(),
            env: [
                'DB_DATABASE' => $fakeProductionShapedDatabase,
                'GUARD_PROBE_SAFE_DATABASE' => $realTestDatabase,
            ],
        );
        $process->run();

        $output = $process->getOutput().$process->getErrorOutput();

        $this->assertTrue(
            $process->isSuccessful(),
            "Probe process exited unsuccessfully (code {$process->getExitCode()}).\n".$output
        );

        $result = ['__raw' => $output];

        foreach (explode("\n", trim($output)) as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $result[$key] = $value;
        }

        $this->assertSame(
            $fakeProductionShapedDatabase,
            $result['CONFIG_DB'] ?? null,
            "Probe did not resolve config('database...database') to the overridden name -- ".
            "the probe itself is not exercising the intended scenario.\n".$output
        );

        return $result;
    }
}
