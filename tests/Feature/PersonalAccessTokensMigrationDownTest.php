<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Tests\TestCase;

/**
 * Regression coverage for the blocker fixed in
 * database/migrations/2026_07_22_120000_create_personal_access_tokens_table.php.
 *
 * That migration's up() is guarded (Schema::hasTable() short circuit), so on
 * sandbox/live it never creates the table, but the migration still gets
 * recorded in the migrations table. down() used to be the unguarded
 * Schema::dropIfExists('personal_access_tokens'), so a migrate:rollback
 * after this migration landed would DROP a table this migration never
 * created, one holding live ApiClient tokens and every mobile device
 * session. This test proves down() can no longer do that.
 *
 * Runs against a disposable scratch database (like
 * DatabaseGuardPreventsWipeTest's pattern), not the shared ventasync_test
 * schema and nowhere near the real sandbox/live personal_access_tokens
 * table, so it can safely simulate "the table already existed before this
 * migration ran" without risking any real data.
 */
class PersonalAccessTokensMigrationDownTest extends TestCase
{
    private string $scratchDatabase;

    private string $originalDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratchDatabase = 'zzz_pat_migration_scratch_'.bin2hex(random_bytes(4));
        $this->originalDatabase = (string) config('database.connections.mysql.database');

        $admin = $this->adminPdo();
        $admin->exec("DROP DATABASE IF EXISTS `{$this->scratchDatabase}`");
        $admin->exec("CREATE DATABASE `{$this->scratchDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        config(['database.connections.mysql.database' => $this->scratchDatabase]);
        DB::purge('mysql');
    }

    protected function tearDown(): void
    {
        config(['database.connections.mysql.database' => $this->originalDatabase]);
        DB::purge('mysql');

        $this->adminPdo()->exec("DROP DATABASE IF EXISTS `{$this->scratchDatabase}`");

        parent::tearDown();
    }

    public function test_down_does_not_drop_a_pre_existing_table_it_did_not_create(): void
    {
        // Simulate sandbox/live: the table already exists before this
        // migration ever runs (created elsewhere, the way Sanctum's old
        // auto-loaded migration created it here), and holds a row this
        // migration must never be able to destroy.
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('tokenable_type');
            $table->unsignedBigInteger('tokenable_id');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => 'App\\Models\\ApiClient',
            'tokenable_id' => 1,
            'name' => 'live-api-client-token',
            'token' => hash('sha256', 'scratch-token'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require base_path('database/migrations/2026_07_22_120000_create_personal_access_tokens_table.php');

        // up() must be a guarded no-op here, the table already exists.
        $migration->up();
        $this->assertSame(1, DB::table('personal_access_tokens')->count());

        // The regression under test: down() must not drop (or otherwise
        // touch) a table this migration did not create.
        $migration->down();

        $this->assertTrue(
            Schema::hasTable('personal_access_tokens'),
            'down() dropped a personal_access_tokens table it did not create. '.
            'This is the production data-loss bug the migration guard exists to prevent.'
        );
        $this->assertSame(
            1,
            DB::table('personal_access_tokens')->count(),
            'down() destroyed rows in a personal_access_tokens table it did not create.'
        );
    }

    private function adminPdo(): PDO
    {
        return new PDO(
            sprintf(
                'mysql:host=%s;port=%s',
                config('database.connections.mysql.host'),
                config('database.connections.mysql.port')
            ),
            config('database.connections.mysql.username'),
            config('database.connections.mysql.password'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
}
