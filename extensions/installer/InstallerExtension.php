<?php

namespace Extensions\installer;

use App\Extensions\ExtensionProvider;
use Extensions\installer\Console\Commands\InstallBypass;
use Extensions\installer\Middleware\InstallationMiddleware;
use Illuminate\Contracts\Http\Kernel;

/**
 * Setup Wizard extension. Self-contained installer that runs at /install
 * on a fresh deploy. The "system: true" flag in extension.json signals
 * that this extension must load from filesystem regardless of DB state
 * (the extensions table doesn't exist before migration).
 *
 * Boot order:
 *   1. ExtensionServiceProvider's system-extension loop runs FIRST
 *      (before the regular DB-driven loop) and registers this provider.
 *   2. We push InstallationMiddleware onto the global stack so every
 *      request gets gated.
 *   3. The middleware itself decides whether to redirect (not installed)
 *      or pass through (installed) — the redirect logic stays in one
 *      file, the actual install controller is loaded only when needed.
 *
 * After install completes, the middleware short-circuits all /install/*
 * requests back to /. The customer can `rm -rf extensions/installer/`
 * to remove the extension entirely; the app keeps working because
 * ExtensionServiceProvider tolerates missing extensions.
 */
class InstallerExtension extends ExtensionProvider
{
    protected string $id = 'installer';

    public function boot(): void
    {
        parent::boot();

        // Push the installation gate onto the global middleware stack.
        // This is the load-bearing piece — it runs on every request and
        // routes traffic appropriately.
        $kernel = $this->app->make(Kernel::class);
        if (method_exists($kernel, 'pushMiddleware')) {
            $kernel->pushMiddleware(InstallationMiddleware::class);
        }

        // Register the Cloud install-bypass Artisan command. Used by the
        // Docker entrypoint when AGILA_INSTALL_BYPASS=true.
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallBypass::class,
            ]);
        }
    }
}
