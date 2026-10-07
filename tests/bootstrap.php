<?php

declare(strict_types=1);

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
