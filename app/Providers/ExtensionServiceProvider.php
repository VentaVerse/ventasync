<?php

namespace App\Providers;

use App\Extensions\ExtensionManager;
use App\Services\LicenseService;
use Illuminate\Support\ServiceProvider;

class ExtensionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ExtensionManager::class, function () {
            return new ExtensionManager();
        });
    }

    public function boot(): void
    {
        $manager = $this->app->make(ExtensionManager::class);
        $manifests = $manager->getManifests();
        $license = $this->app->make(LicenseService::class);

        // ─── System extensions (load FIRST, no DB dependency) ──────────────
        // Extensions with "system": true in their manifest must boot from
        // filesystem regardless of DB state. This is how the installer
        // extension can run before the `extensions` table even exists.
        // System extensions are also exempt from license_required checks
        // (they're foundational, not paid).
        foreach ($manifests as $id => $manifest) {
            if (!($manifest['system'] ?? false)) {
                continue;
            }
            $providerClass = $manager->getProviderClass($id);
            if ($providerClass && class_exists($providerClass)) {
                $this->app->register($providerClass);
            }
            $langPath = base_path("extensions/{$id}/lang");
            if (is_dir($langPath)) {
                $this->loadTranslationsFrom($langPath);
            }
        }

        // ─── Regular extensions (DB-dependent, license-gated) ──────────────
        $enabledIds = $manager->getEnabledIds();

        foreach ($enabledIds as $id) {
            if (!isset($manifests[$id])) {
                continue;
            }

            // Skip extensions that require a license but are not activated.
            // Providers, routes, nav, and permissions all depend on registration,
            // so this one check cleanly withholds the entire module from the app.
            $licenseRequired = !empty($manifests[$id]['license_required']);
            if ($licenseRequired && !$license->isModuleLicensed($id)) {
                continue;
            }

            $providerClass = $manager->getProviderClass($id);
            if ($providerClass && class_exists($providerClass)) {
                $this->app->register($providerClass);
            }

            $langPath = base_path("extensions/{$id}/lang");
            if (is_dir($langPath)) {
                $this->loadTranslationsFrom($langPath);
            }

            // Auto-sync permissions on boot (cached to avoid DB queries every request)
            $cacheKey = 'ext_perms_synced_' . $id . '_' . md5(json_encode($manifests[$id]['permissions'] ?? []));
            if (!cache()->has($cacheKey)) {
                $manager->syncPermissions($id);
                cache()->put($cacheKey, true, now()->addHours(24));
            }
        }

        $this->app['view']->composer('layouts.app', function ($view) {
            $manager = $this->app->make(ExtensionManager::class);
            $view->with('extensionNavGroups', $manager->getNavItems());
        });
    }
}
