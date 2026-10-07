<?php

namespace App\Extensions;

use App\Mcp\McpToolRegistry;
use Illuminate\Support\ServiceProvider;

abstract class ExtensionProvider extends ServiceProvider
{
    protected string $id = '';

    protected string $basePath = '';

    public function register(): void
    {
        if ($this->id === '') {
            return;
        }

        $this->basePath = base_path('extensions/' . $this->id);

        $this->app->singletonIf(McpToolRegistry::class);

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
        $this->app->make(McpToolRegistry::class)->add($this->mcpTools());
        $this->bootViews();
        $this->bootMigrations();
        $this->bootTranslations();
        ExtensionImages::publish($this->id);
    }

    protected function mcpTools(): array
    {
        return [];
    }

    protected function bootRoutes(): void
    {
        $routeFile = $this->basePath . '/routes/web.php';
        if (file_exists($routeFile)) {
            $this->app['router']->middleware('web')->group($routeFile);
        }

        $apiRouteFile = $this->basePath . '/routes/api.php';
        if (file_exists($apiRouteFile)) {
            $this->app['router']
                ->prefix('api/v1')
                ->middleware(['auth:sanctum', 'api.limits', 'api.audit'])
                ->group($apiRouteFile);
        }

        $appRouteFile = $this->basePath . '/routes/app.php';
        if (file_exists($appRouteFile)) {
            \App\Support\AppDoor::mount($this->app['router'], ['api', 'auth:sanctum', 'app.person', 'app.once'], $appRouteFile);
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

    public function automations(): array
    {
        return [];
    }

    public function automationStoreIds(): ?array
    {
        return null;
    }
}
