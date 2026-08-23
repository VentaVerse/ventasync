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
            // Same boot resilience AppServiceProvider gives the settings
            // singleton: on a fresh deploy before migrations, or with the
            // database absent or down, the sync must not take the whole boot
            // down with it. This was masked for months by an accident: the
            // cache always resolved to the FILE driver (config/cache.php reads
            // CACHE_DRIVER, everything else sets CACHE_STORE), so a warm
            // synced marker skipped the DB query that would have crashed. The
            // moment a cold or array cache runs this line honestly, a missing
            // schema crashed every boot, including the destructive-command
            // guard test's probe, which boots against a database that does
            // not exist BY DESIGN.
            try {
                if (!cache()->has($coreCacheKey)) {
                    $manager->syncCorePermissions();
                    cache()->put($coreCacheKey, true, now()->addHours(24));
                }
            } catch (\Throwable) {
                // Fall through and let the request continue; the sync runs on
                // the next boot with a working database.
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

        }

        /* ─── Derived permission sync, ONCE, after every provider is in ────
         *
         * Permission rows derive from the route table, so the sync can only
         * run after ALL extension providers have registered their routes -
         * running it per extension inside the loop above (as the manifest
         * sync used to) would derive from a half-built table. booted() is
         * the first moment the routes are complete.
         *
         * Cached on a fingerprint of the DERIVED KEY LIST ITSELF - not the
         * enabled-extension list, which misses the case that bit on day
         * one: a controller split changes the keys without changing which
         * extensions are enabled. Deriving the list to fingerprint it costs
         * a route-table walk the door middleware already pays per request;
         * what the cache saves is the DB reads. Same try/catch boot
         * resilience as the core sync above, for the same reason. */
        $this->app->booted(function () use ($manager) {
            $keys = app(\App\Services\PermissionCatalogue::class)->keys();
            sort($keys);
            $syncKey = 'derived_perms_synced_' . md5(json_encode($keys));
            try {
                if (!cache()->has($syncKey)) {
                    $manager->syncPermissions();
                    cache()->put($syncKey, true, now()->addHours(24));
                }
            } catch (\Throwable) {
                // Next boot with a working database re-syncs.
            }
        });

        // No view composer here any more. This used to bind
        // $extensionNavGroups onto layouts.app, which was the only view that
        // ever read it: that layout walked the array itself to print its
        // "Extension Nav Groups" sidebar section. layouts.app was deleted in
        // Round F Task 4, and the surviving nav reads the same source through
        // Navigation::extensionEntries(), which calls
        // ExtensionManager::getNavItems() directly at render time. Leaving the
        // composer would have bound a variable no template consumes.
    }
}
