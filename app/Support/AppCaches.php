<?php

namespace App\Support;

use App\Extensions\ExtensionManager;
use App\Integrations\Support\AutomationCatalogue;
use App\Services\PermissionHierarchy;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

final class AppCaches
{
    public static function refresh(): void
    {
        $app = app();

        foreach ([$app->getCachedRoutesPath(), $app->getCachedConfigPath(), $app->getCachedEventsPath()] as $file) {
            if (File::exists($file)) {
                File::delete($file);
            }
        }

        $compiled = (string) config('view.compiled');
        if ($compiled !== '' && File::isDirectory($compiled)) {
            foreach (File::glob($compiled . '/*.php') as $view) {
                File::delete($view);
            }
        }

        $app->make(ExtensionManager::class)->syncPermissions();
        $app->make(PermissionHierarchy::class)->invalidate();

        if (Schema::hasTable('scheduled_jobs')) {
            $app->make(AutomationCatalogue::class)->sync();
        }
    }
}
