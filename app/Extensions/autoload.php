<?php

// Extension folders are named by their lowercase id; their namespaces use the brand's capitals.
spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'Extensions\\', 11) !== 0) {
        return;
    }

    $parts = explode('\\', substr($class, 11));
    if (count($parts) < 2) {
        return;
    }

    $parts[0] = strtolower($parts[0]);
    $file = dirname(__DIR__, 2) . '/extensions/' . implode('/', $parts) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
