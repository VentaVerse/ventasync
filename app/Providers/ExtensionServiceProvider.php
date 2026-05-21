<?php

namespace App\Providers;

use App\Extensions\ExtensionManager;
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

        // ─── Core permissions (auto-sync from config/permissions.php) ──────
        // Core permissions are CODE — every install should converge on the
        // same set of keys without manual tinker. Cache key fingerprints the
        // declared keys, so any edit to config/permissions.php auto-triggers
        // a re-sync on the next boot.
        $coreKeys = array_keys((array) config('permissions', []));
        if (!empty($coreKeys)) {
            $coreCacheKey = 'core_perms_synced_' . md5(json_encode($coreKeys));
            if (!cache()->has($coreCacheKey)) {
                $manager->syncCorePermissions();
                cache()->put($coreCacheKey, true, now()->addHours(24));
            }
        }

        // ─── System extensions (load FIRST, no DB dependency) ──────────────
        // Extensions with "system": true in their manifest boot from
        // filesystem regardless of DB state — useful for any extension
        // that must run before the `extensions` table exists or is
        // populated.
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

        // ─── Regular extensions (DB-dependent) ─────────────────────────────
        $enabledIds = $manager->getEnabledIds();

        foreach ($enabledIds as $id) {
            if (!isset($manifests[$id])) {
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
