<?php

namespace App\Providers;

use App\Models\LazadaOrder;
use App\Models\LazadaSetting;
use App\Models\OpenCartSetting;
use App\Models\Setting;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Avoid errors during first migrations / before table exists
        if (Schema::hasTable('settings')) {
            $setting = Setting::singleton();

            // Dynamic mail "from" values controlled by admin settings
            Config::set('mail.from.address', $setting->from_email);
            Config::set('mail.from.name', $setting->company_name);

            // Make available to all blade views as $appSetting
            View::share('appSetting', $setting);
        }


        // Share Lazada pending-orders count with the sidebar nav
        View::composer('layouts.app', function ($view) {
            try {
                if (!Schema::hasTable('lazada_orders')) {
                    $view->with('lazadaPendingCount', 0);
                    return;
                }
                $region = 'ph';
                if (Schema::hasTable('lazada_settings')) {
                    $region = LazadaSetting::query()->value('region') ?? 'ph';
                }
                $count = LazadaOrder::query()
                    ->where('region', $region)
                    ->whereIn('status', ['pending', 'packed', 'ready_to_ship'])
                    ->count();
                $view->with('lazadaPendingCount', $count);
            } catch (\Throwable $e) {
                $view->with('lazadaPendingCount', 0);
            }

            // Share enabled OpenCart stores for sidebar per-store links
            try {
                if (Schema::hasTable('opencart_settings')) {
                    $view->with('opencartStores', OpenCartSetting::where('enabled', true)->get());
                } else {
                    $view->with('opencartStores', collect());
                }
            } catch (\Throwable $e) {
                $view->with('opencartStores', collect());
            }
        });

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
