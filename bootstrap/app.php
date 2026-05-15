<?php

/*
|--------------------------------------------------------------------------
| Ensure runtime directories exist
|--------------------------------------------------------------------------
|
| Laravel writes to several directories on first request (compiled views,
| cached config, sessions, logs). If the zip ships without these dirs
| (e.g., they were gitignored or build-script-excluded), Laravel crashes
| with a write error before any controller can render a useful page.
|
| Cheap to run on every request: ~8 stat calls. Only triggers mkdir on
| the very first request when dirs are missing.
|
*/

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

/*
|--------------------------------------------------------------------------
| Create The Application
|--------------------------------------------------------------------------
|
| The first thing we will do is create a new Laravel application instance
| which serves as the "glue" for all the components of Laravel, and is
| the IoC container for the system binding all of the various parts.
|
*/

$app = new Illuminate\Foundation\Application(
    $_ENV['APP_BASE_PATH'] ?? dirname(__DIR__)
);

/*
|--------------------------------------------------------------------------
| Bind Important Interfaces
|--------------------------------------------------------------------------
|
| Next, we need to bind some important interfaces into the container so
| we will be able to resolve them when needed. The kernels serve the
| incoming requests to this application from both the web and CLI.
|
*/

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

/*
|--------------------------------------------------------------------------
| Return The Application
|--------------------------------------------------------------------------
|
| This script returns the application instance. The instance is given to
| the calling script so we can separate the building of the instances
| from the actual running of the application and sending responses.
|
*/

return $app;
