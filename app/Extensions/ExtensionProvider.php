<?php

namespace App\Extensions;

use Illuminate\Support\ServiceProvider;

abstract class ExtensionProvider extends ServiceProvider
{
    /** Unique extension identifier — must match extension.json "id" */
    protected string $id = '';

    /** Resolved base path of the extension directory */
    protected string $basePath = '';

    public function register(): void
    {
        if ($this->id === '') {
            return;
        }

        $this->basePath = base_path('extensions/' . $this->id);

        // Merge config files if config/ directory exists
        $configPath = $this->basePath . '/config';
        if (is_dir($configPath)) {
            foreach (glob($configPath . '/*.php') as $file) {
                $key = 'ext.' . $this->id . '.' . basename($file, '.php');
                $this->mergeConfigFrom($file, $key);
            }
        }
    }

    public function boot(): void
    {
        if ($this->id === '' || $this->basePath === '') {
            return;
        }

        $this->bootRoutes();
        $this->bootViews();
        $this->bootMigrations();
        $this->bootTranslations();
    }

    // IMPORTANT: Use different method names than parent to avoid recursion
    // Call parent::loadRoutesFrom(), parent::loadViewsFrom(), parent::loadMigrationsFrom()

    protected function bootRoutes(): void
    {
        $routeFile = $this->basePath . '/routes/web.php';
        if (file_exists($routeFile)) {
            $this->app['router']->middleware('web')->group($routeFile);
        }
    }

    protected function bootViews(): void
    {
        $viewsPath = $this->basePath . '/views';
        if (is_dir($viewsPath)) {
            parent::loadViewsFrom($viewsPath, 'ext-' . $this->id);
        }
    }

    protected function bootMigrations(): void
    {
        $migrationsPath = $this->basePath . '/migrations';
        if (is_dir($migrationsPath)) {
            parent::loadMigrationsFrom($migrationsPath);
        }
    }

    /**
     * Auto-load the extension's translation files under the `ext-{id}` namespace
     * so core can resolve display strings (permission labels, UI text, etc.)
     * via Laravel's translator without knowing which extension owns the key.
     *
     * Layout:
     *   extensions/{id}/lang/en/permissions.php
     *   extensions/{id}/lang/tl/permissions.php   (when shipping new locales)
     *
     * Resolution from core:
     *   __('ext-shopee::permissions.manage_shopee.label')
     */
    protected function bootTranslations(): void
    {
        $langPath = $this->basePath . '/lang';
        if (is_dir($langPath)) {
            parent::loadTranslationsFrom($langPath, 'ext-' . $this->id);
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }
}
