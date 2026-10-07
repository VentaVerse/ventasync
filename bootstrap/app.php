<?php

$__runtimeDirs = [
    'bootstrap/cache',
    'storage/framework/cache/data',
    'storage/framework/sessions',
    'storage/framework/views',
    'storage/logs',
    'storage/app/private',
    'storage/app/public',
];
foreach ($__runtimeDirs as $__dir) {
    $__path = dirname(__DIR__) . '/' . $__dir;
    if (!is_dir($__path)) {
        @mkdir($__path, 0775, true);
    }
}
unset($__runtimeDirs, $__dir, $__path);

$app = new Illuminate\Foundation\Application(
    $_ENV['APP_BASE_PATH'] ?? dirname(__DIR__)
);

$app->singleton(
    Illuminate\Contracts\Http\Kernel::class,
    App\Http\Kernel::class
);

$app->singleton(
    Illuminate\Contracts\Console\Kernel::class,
    App\Console\Kernel::class
);

$app->singleton(
    Illuminate\Contracts\Debug\ExceptionHandler::class,
    App\Exceptions\Handler::class
);

return $app;
