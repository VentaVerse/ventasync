<?php

namespace Tests\Feature\Support;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nested-process probe used by DatabaseGuardPreventsWipeTest to prove the DB_DATABASE
 * `_test`-suffix guard actually PREVENTS RefreshDatabase from wiping a non-test schema,
 * not merely detects it after the fact.
 *
 * Deliberately named without the "Test.php" suffix so PHPUnit's directory-based discovery
 * (tests/Feature, default <directory> suffix "Test.php") never picks it up as part of the
 * normal suite - it only ever runs when DatabaseGuardPreventsWipeTest invokes it directly by
 * file path in a separate process pointed at a scratch database.
 *
 * It genuinely `use`s RefreshDatabase so that if the guard failed to run in time, this class
 * would actually trigger `migrate:fresh` -> `db:wipe` against whatever DB_DATABASE names.
 */
class RefreshDatabaseScratchProbe extends TestCase
{
    use RefreshDatabase;

    public function test_it_only_ever_runs_against_an_already_verified_database(): void
    {
        $this->assertTrue(true);
    }
}
