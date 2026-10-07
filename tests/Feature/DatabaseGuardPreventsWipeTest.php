<?php

namespace Tests\Feature;

use PDO;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DatabaseGuardPreventsWipeTest extends TestCase
{
    private string $scratchDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratchDatabase = 'zzz_guard_scratch_'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->adminPdo()->exec("DROP DATABASE IF EXISTS `{$this->scratchDatabase}`");

        parent::tearDown();
    }

    public function test_refresh_database_never_wipes_a_schema_whose_name_does_not_end_in_test(): void
    {
        $this->seedScratchDatabase();

        $probe = base_path('tests/Feature/Support/RefreshDatabaseScratchProbe.php');

        $process = new Process(
            command: [PHP_BINARY, 'vendor/bin/phpunit', $probe],
            cwd: base_path(),
            env: ['DB_DATABASE' => $this->scratchDatabase],
        );
        $process->run();

        $output = $process->getOutput().$process->getErrorOutput();

        $this->assertFalse(
            $process->isSuccessful(),
            "Expected the nested run against '{$this->scratchDatabase}' (not `_test`-suffixed) ".
            "to be refused by the guard, but it succeeded.\n".$output
        );

        $this->assertStringContainsString(
            "Refusing to run tests against database '{$this->scratchDatabase}'",
            $output,
            "Nested run failed, but not with the expected guard message.\n".$output
        );

        $tables = $this->scratchTables();

        $this->assertContains(
            'migrations',
            $tables,
            "migrate:fresh ran db:wipe against '{$this->scratchDatabase}': the migrations table was dropped."
        );

        $this->assertContains(
            'marker_do_not_wipe',
            $tables,
            "migrate:fresh ran db:wipe against '{$this->scratchDatabase}': the marker table was dropped."
        );
    }

    private function seedScratchDatabase(): void
    {
        $admin = $this->adminPdo();
        $admin->exec("DROP DATABASE IF EXISTS `{$this->scratchDatabase}`");
        $admin->exec("CREATE DATABASE `{$this->scratchDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $scoped = $this->scratchPdo();
        $scoped->exec(
            'CREATE TABLE migrations ('.
            'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, '.
            'migration VARCHAR(255) NOT NULL, '.
            'batch INT NOT NULL'.
            ')'
        );
        $scoped->exec("INSERT INTO migrations (migration, batch) VALUES ('0000_00_00_000000_fake_migration', 1)");

        $scoped->exec('CREATE TABLE marker_do_not_wipe (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, note VARCHAR(64))');
        $scoped->exec("INSERT INTO marker_do_not_wipe (note) VALUES ('still here')");
    }

    private function scratchTables(): array
    {
        $stmt = $this->scratchPdo()->query('SHOW TABLES');

        if ($stmt === false) {
            throw new RuntimeException("Failed to list tables in scratch database '{$this->scratchDatabase}'.");
        }

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function adminPdo(): PDO
    {
        return new PDO(
            sprintf('mysql:host=%s;port=%s', config('database.connections.mysql.host'), config('database.connections.mysql.port')),
            config('database.connections.mysql.username'),
            config('database.connections.mysql.password'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    private function scratchPdo(): PDO
    {
        return new PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s',
                config('database.connections.mysql.host'),
                config('database.connections.mysql.port'),
                $this->scratchDatabase
            ),
            config('database.connections.mysql.username'),
            config('database.connections.mysql.password'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
}
