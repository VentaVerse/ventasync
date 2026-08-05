<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Hard guard against running the suite on a non-test database.
     *
     * Tests use MySQL (the catalog schema needs a FULLTEXT index sqlite cannot
     * create). RefreshDatabase drops and rebuilds every table in the database
     * it is pointed at, so a mistyped DB_DATABASE would destroy the sandbox or,
     * worse, live data. Refuse to run unless the schema name ends in `_test`.
     *
     * This check MUST run before parent::setUp(), not after. Illuminate\Foundation\
     * Testing\TestCase::setUp() internally calls setUpTraits(), which - for any leaf
     * class using RefreshDatabase - runs `migrate:fresh` (and therefore `db:wipe`)
     * against whatever database is configured, before control ever returns here. A
     * check placed after parent::setUp() only detects the misconfiguration once the
     * damage is already done. So this reads the raw environment directly (config()
     * isn't bootstrapped yet at this point - the framework hasn't been booted) rather
     * than calling config('database...'). phpunit.xml's <php><env> block guarantees
     * DB_DATABASE is set before the process ever gets here.
     *
     * This is also enforced one layer earlier, in tests/bootstrap.php, which runs
     * before any test class is instantiated at all and so cannot be bypassed by a
     * class that overrides setUp() without calling parent::setUp(). Keeping both is
     * deliberate defense in depth - see that file's docblock.
     */
    protected function setUp(): void
    {
        $database = getenv('DB_DATABASE');

        if ($database === false || $database === '') {
            $database = $_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? '';
        }

        if (! is_string($database) || $database === '' || ! str_ends_with($database, '_test')) {
            throw new RuntimeException(
                "Refusing to run tests against database '{$database}': the name must end in '_test'. ".
                'Check DB_DATABASE in phpunit.xml.'
            );
        }

        parent::setUp();
    }
}
