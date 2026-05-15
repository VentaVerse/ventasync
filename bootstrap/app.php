<?php

/*
|--------------------------------------------------------------------------
| Bootstrap a minimal .env if missing (idiot-proof install)
|--------------------------------------------------------------------------
|
| On a fresh drag-and-drop install the customer has not edited any files.
| Without a .env, Laravel can't boot (no APP_KEY, no DB config, sessions
| can't encrypt). This pre-flight step creates a minimal .env with safe
| defaults the first time the application runs.
|
| File-backed sessions and cache mean the installer wizard works without
| any DB. After the wizard finishes, .env contains the customer's real
| DB credentials and the normal request lifecycle takes over.
|
| Idempotent — runs once on first request, then the file_exists check
| skips it on every subsequent request.
|
*/

$__envPath = dirname(__DIR__) . '/.env';
if (!file_exists($__envPath)) {
    $__appKey = 'base64:' . base64_encode(random_bytes(32));
    $__minimalEnv = <<<ENV
APP_NAME="VentaSync"
APP_ENV=production
APP_KEY={$__appKey}
APP_DEBUG=false
APP_URL=http://localhost

LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=warning

# File-backed by default so the installer works with no DB.
# The wizard can switch these to database after it provisions tables.
SESSION_DRIVER=file
SESSION_LIFETIME=120
CACHE_STORE=file
QUEUE_CONNECTION=sync
BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=

MAIL_MAILER=log
MAIL_FROM_ADDRESS="noreply@example.com"
MAIL_FROM_NAME="\${APP_NAME}"

VITE_APP_NAME="\${APP_NAME}"
ENV;
    @file_put_contents($__envPath, $__minimalEnv);
    @chmod($__envPath, 0644);
}
unset($__envPath, $__appKey, $__minimalEnv);

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
| Cheap to run on every request: ~7 stat calls. Only triggers mkdir on
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
    'storage/agila',
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
