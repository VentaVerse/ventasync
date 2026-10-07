<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
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
