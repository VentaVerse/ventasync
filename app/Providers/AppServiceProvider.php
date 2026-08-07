<?php

namespace App\Providers;

use App\Models\Catalog\Order;
use App\Models\Setting;
use App\Observers\OrderObserver;
use App\Support\ChannelWorkspace;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
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
        // Refuse to run schema-destroying artisan commands (migrate:fresh,
        // migrate:refresh, migrate:reset, migrate:rollback, db:wipe) against
        // any database whose name does not end in `_test`.
        //
        // Why this exists: `migrate:fresh` calls `db:wipe`, which drops every
        // table in whatever DB_DATABASE points at. On 2026-08-05 that wiped the
        // sandbox (44,172 orders) mid-session - recovered from a dump, but only
        // because one had been taken. The test suite already guards itself
        // (tests/bootstrap.php + Tests\TestCase), but that only covers PHPUnit;
        // a bare `php artisan migrate:fresh` reads .env and bypasses it
        // entirely. This closes that path for real data while leaving the
        // `*_test` schemas freely rebuildable.
        $database = (string) config('database.connections.'.config('database.default').'.database');
        DB::prohibitDestructiveCommands(! str_ends_with($database, '_test'));

        // layouts.channel needs the card, the sidebar groups and the state in
        // every view that extends it, including the seven existing Shopee
        // pages. A composer bound to the layout puts them exactly where they
        // are needed. View::share() would leak them onto every page in the
        // app, and adding them to seven controller methods by hand is the
        // kind of duplication that drifts.
        // Bound to the layout AND to the workspace Overview view. With
        // @extends the child renders its sections first, so a composer on the
        // layout alone leaves $channelCard null inside the child's own
        // content (the Overview reads the tagline and the state there). The
        // request-attributes memo keeps it one computation per request even
        // though the composer now fires for two views; request attributes
        // rather than a static so nothing leaks across tests sharing a
        // process.
        View::composer(['layouts.channel', 'integrations.workspace'], function ($view) {
            $payload = request()->attributes->get('channel-workspace.compose');

            if ($payload === null) {
                $workspace = app(ChannelWorkspace::class);
                $user = request()->user();

                // ext.shopee.orders.index -> shopee
                //
                // On a multi-store channel every page is store-scoped, so the
                // route's own {store} parameter completes the key:
                // ext.venta.orders.index on /venta/3/orders -> venta:3, the
                // same format StoreLink and OrderTab ids already use. A
                // channel with no {store} parameter is unaffected, and a
                // store suffix on a single-store channel resolves to null
                // (404) rather than to the channel, so this cannot quietly
                // widen what a key reaches.
                $route = request()->route();
                $name = (string) ($route?->getName() ?? '');

                if (str_starts_with($name, 'ext.')) {
                    $channel = explode('.', $name)[1] ?? '';
                    $store = $route?->parameter('store');

                    if ($channel !== '' && is_scalar($store) && (string) $store !== '') {
                        $channel .= ':' . $store;
                    }
                } else {
                    // /integrations/venta/2 arrives here as `venta/2`; the key
                    // every reader below expects is `venta:2`. Same boundary
                    // translation ChannelWorkspaceController::show applies.
                    $channel = ChannelWorkspace::keyFromPath((string) request()->route('channel'));
                }

                // Deliberately the ungated lookup. Every page reaching this
                // composer has already cleared its own route's perm: middleware,
                // and the card's permission is a different, broader question -
                // re-asking it here 404s users the route just let through (a
                // view_shopee_orders-only operator on /shopee/orders, for
                // instance). ChannelWorkspaceController::show keeps the gated
                // card() for /integrations/{channel}, where the card permission
                // IS the right question, and it runs before this composer.
                $card = $workspace->cardForAuthorisedPage($channel, $user);

                if (! $card) {
                    abort(404);
                }

                $payload = [
                    'channelCard' => $card,
                    // The full workspace key, so every link the chrome builds
                    // back to the Overview stays in the store the operator is
                    // standing in.
                    'channelKey' => $channel,
                    // Which store this is, for the sidebar head and the
                    // breadcrumb. Null on a single-store channel, where the
                    // channel name already answers the question.
                    'channelStoreLabel' => $workspace->store($card, $channel)?->label,
                    'channelGroups' => $workspace->groups($card, $user, $channel),
                    'channelState' => $workspace->state($card, $channel),
                    // Whether this user may open /integrations/{channel},
                    // which ChannelWorkspaceController::show gates on the
                    // CARD permission via the gated card(). The sidebar's
                    // Overview link renders only when this is true, so it
                    // never offers a destination that will 404.
                    'channelHasOverview' => $workspace->card($channel, $user) !== null,
                ];

                request()->attributes->set('channel-workspace.compose', $payload);
            }

            $view->with($payload);
        });

        // Centralized FCM notification dispatch - fires on any Order create/update.
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
            // DB unreachable or settings table missing - let the request
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
