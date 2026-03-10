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
        $enabledIds = $manager->getEnabledIds();
        $manifests = $manager->getManifests();

        foreach ($enabledIds as $id) {
            if (!isset($manifests[$id])) {
                continue;
            }

            $providerClass = $manager->getProviderClass($id);
            if ($providerClass && class_exists($providerClass)) {
                $this->app->register($providerClass);
            }
        }

        $this->app['view']->composer('layouts.app', function ($view) {
            $manager = $this->app->make(ExtensionManager::class);
            $view->with('extensionNavGroups', $manager->getNavItems());
        });
    }
}
