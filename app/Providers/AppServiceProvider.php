<?php

namespace App\Providers;

use App\Models\Catalog\Order;
use App\Models\Setting;
use App\Observers\OrderObserver;
use App\Support\ChannelWorkspace;
use App\Support\FulfilmentBadge;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        \Laravel\Passport\Passport::$deviceCodeGrantEnabled = false;
        \Laravel\Passport\Passport::ignoreRoutes();
        $this->app->singleton(\App\Integrations\IntegrationRegistry::class);
        $this->app->singleton(\App\Services\Payouts\PayoutRegistry::class);
    }

    public function boot(): void
    {
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Login::class, function (\Illuminate\Auth\Events\Login $event) {
            if (request()->hasSession()) {
                request()->session()->forget('password_hash_' . $event->guard);
            }
        });


        // migrate:fresh and db:wipe drop every table. Allowed only on a database whose name ends in _test.
        $database = (string) config('database.connections.'.config('database.default').'.database');
        DB::prohibitDestructiveCommands(! str_ends_with($database, '_test'));

        View::composer('partials.nav-blotter', function ($view) {
            $view->with('navBadges', [
                'channels.fulfilment' => FulfilmentBadge::pending(auth()->user()),
            ]);
        });

        View::composer(['layouts.channel', 'channels.workspace'], function ($view) {
            $payload = request()->attributes->get('channel-workspace.compose');

            if ($payload === null) {
                $workspace = app(ChannelWorkspace::class);
                $user = request()->user();

                $route = request()->route();
                $name = (string) ($route?->getName() ?? '');

                $channel = str_starts_with($name, 'ext.') ? (explode('.', $name)[1] ?? '') : '';
                $store = $route?->parameter('store') ?? request()->attributes->get('channel.store');

                if ($channel !== '' && is_scalar($store) && (string) $store !== '') {
                    $channel .= ':' . $store;
                }

                $card = $workspace->cardForAuthorisedPage($channel, $user);

                if (! $card) {
                    abort(404);
                }

                $channelState = $workspace->stateWithReason($card, $channel);

                $payload = [
                    'channelCard' => $card,
                    'channelKey' => $channel,
                    'channelStoreLabel' => $workspace->store($card, $channel)?->label,
                    'channelGroups' => $workspace->groups($card, $user, $channel),
                    'channelState' => $channelState['state'],
                    'channelStateReason' => $channelState['reason'],
                    'channelHasOverview' => $workspace->card($channel, $user) !== null,
                ];

                request()->attributes->set('channel-workspace.compose', $payload);
            }

            $view->with($payload);
        });

        Order::observe(OrderObserver::class);

        try {
            if (Schema::hasTable('settings')) {
                $setting = Setting::singleton();

                Config::set('mail.from.address', $setting->from_email);
                Config::set('mail.from.name', $setting->company_name);

                if ($setting->timezone) {
                    Config::set('app.timezone', $setting->timezone);
                    date_default_timezone_set($setting->timezone);
                }

                View::share('appSetting', $setting);
            }
        } catch (\Throwable $e) {
        }


        $logPath = storage_path('logs/error.log');
        $logDir = dirname($logPath);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        if (!file_exists($logPath)) {
            @touch($logPath);
        }
        @chmod($logPath, 0664);

        $previous = null;
        $previous = set_error_handler(function ($severity, $message, $file = null, $line = null) use (&$previous, $logPath) {
            if (! (error_reporting() & $severity)) {
                return $previous ? (bool) call_user_func($previous, $severity, $message, $file, $line) : false;
            }

            try {
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

            $dir = dirname($logPath);
            if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
            if (!file_exists($logPath)) { @touch($logPath); }
            @chmod($logPath, 0664);

            @file_put_contents($logPath, $lineTxt, FILE_APPEND | LOCK_EX);

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
