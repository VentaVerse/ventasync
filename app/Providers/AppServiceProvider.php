<?php

namespace App\Providers;

use App\Models\Catalog\Order;
use App\Models\Setting;
use App\Observers\OrderObserver;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Integrations\IntegrationRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Centralized FCM notification dispatch — fires on any Order create/update.
        Order::observe(OrderObserver::class);

        // Apply admin Settings to runtime config. Wrapped in try/catch because:
        //   1. On a fresh deploy before migrations run, the settings table
        //      doesn't exist yet. Schema::hasTable would still hit the DB.
        //   2. If .env DB credentials are wrong or the DB service is down,
        //      Schema::hasTable throws PDOException and crashes the boot.
        // If anything goes wrong, fall back to .env / config defaults and
        // let the request continue so the error page or login page can
        // still render.
        try {
            if (Schema::hasTable('settings')) {
                $setting = Setting::singleton();

                // Dynamic mail "from" values controlled by admin settings
                Config::set('mail.from.address', $setting->from_email);
                Config::set('mail.from.name', $setting->company_name);

                // Apply timezone from admin settings
                if ($setting->timezone) {
                    Config::set('app.timezone', $setting->timezone);
                    date_default_timezone_set($setting->timezone);
                }

                // Make available to all blade views as $appSetting
                View::share('appSetting', $setting);
            }
        } catch (\Throwable $e) {
            // DB unreachable or settings table missing — let the request
            // continue and render whatever page is available (login,
            // error page, etc).
        }


        // Capture non-fatal PHP errors (warnings/notices/deprecations) into storage/logs/error.log
        // for cleanup/troubleshooting without exposing them to users.
        $logPath = storage_path('logs/error.log');
        $logDir = dirname($logPath);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        if (!file_exists($logPath)) {
            @touch($logPath);
        }
        // Best-effort: keep log writable for common deploy users/groups
        @chmod($logPath, 0664);

        $previous = null;
        $previous = set_error_handler(function ($severity, $message, $file = null, $line = null) use (&$previous, $logPath) {
            try {
            // Capture all PHP errors passed to the handler (including when error_reporting() is 0).
            $severityName = match ($severity) {
                E_ERROR => 'E_ERROR',
                E_WARNING => 'E_WARNING',
                E_PARSE => 'E_PARSE',
                E_NOTICE => 'E_NOTICE',
                E_CORE_ERROR => 'E_CORE_ERROR',
                E_CORE_WARNING => 'E_CORE_WARNING',
                E_COMPILE_ERROR => 'E_COMPILE_ERROR',
                E_COMPILE_WARNING => 'E_COMPILE_WARNING',
                E_USER_ERROR => 'E_USER_ERROR',
                E_USER_WARNING => 'E_USER_WARNING',
                E_USER_NOTICE => 'E_USER_NOTICE',
                E_STRICT => 'E_STRICT',
                E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
                E_DEPRECATED => 'E_DEPRECATED',
                E_USER_DEPRECATED => 'E_USER_DEPRECATED',
                default => 'E_' . (string) $severity,
            };

            $ts = date('Y-m-d H:i:s');
            $uid = auth()->check() ? ('user_id=' . auth()->id()) : 'guest';
            $uri = isset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'])
                ? ($_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'])
                : 'CLI';

            $lineTxt = sprintf("[%s] %s: %s in %s:%s | %s | %s\n",
                $ts,
                $severityName,
                (string) $message,
                (string) $file,
                (string) $line,
                $uri,
                $uid
            );

            // Best-effort ensure the log file stays writable even after being cleared/rotated by a different user.
            $dir = dirname($logPath);
            if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
            if (!file_exists($logPath)) { @touch($logPath); }
            @chmod($logPath, 0664);

            @file_put_contents($logPath, $lineTxt, FILE_APPEND | LOCK_EX);

            // Chain to previous handler (if any) and allow normal PHP handling.
            if ($previous) {
                try {
                    return (bool) call_user_func($previous, $severity, $message, $file, $line);
                } catch (\Throwable $e) {
                    return false;
                }
            }

            return false;
            } catch (\Throwable $e) {
                return false;
            }
        });

    }
}
