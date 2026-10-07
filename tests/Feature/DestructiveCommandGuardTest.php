<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

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

    private function runProbe(): array
    {
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
