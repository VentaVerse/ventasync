<?php

declare(strict_types=1);

/**
 * Test-suite bootstrap.
 *
 * PHPUnit applies the <php><env> values from phpunit.xml (Configuration\PhpHandler::handle(),
 * via putenv()/$_ENV) BEFORE it loads this bootstrap script, and this script runs BEFORE
 * vendor/autoload.php, before Laravel's Application is created, and before any test class is
 * instantiated. That makes this the earliest possible point to refuse a misconfigured
 * database - earlier than Tests\TestCase::setUp(), which cannot run in time to stop
 * RefreshDatabase (see the docblock there for why the ordering matters).
 *
 * This check is deliberately duplicated in Tests\TestCase::setUp() as defense in depth: this
 * file protects the whole process regardless of which test class runs; TestCase::setUp()
 * protects any individual test that extends it even if phpunit.xml's bootstrap attribute is
 * ever changed back to vendor/autoload.php directly.
 */
$database = getenv('DB_DATABASE');

if ($database === false || $database === '') {
    $database = $_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? '';
}

if (! is_string($database) || $database === '' || ! str_ends_with($database, '_test')) {
    fwrite(
        STDERR,
        "Refusing to run tests against database '{$database}': the name must end in '_test'. ".
        "Check DB_DATABASE in phpunit.xml.".PHP_EOL
    );

    exit(1);
}

require __DIR__.'/../vendor/autoload.php';
