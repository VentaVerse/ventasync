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
            }
        });

        if (!$this->app->runningUnitTests()) {
            $this->app->booted(function () {
                try {
                    $catalogue = app(\App\Integrations\Support\AutomationCatalogue::class);
                    $syncKey = 'automations_synced_' . $catalogue->fingerprint();
                    if (!cache()->has($syncKey) && \Illuminate\Support\Facades\Schema::hasTable('scheduled_jobs')) {
                        $catalogue->sync();
                        cache()->put($syncKey, true, now()->addHours(24));
                    }
                } catch (\Throwable) {
                }
            });
        }
    }
}
