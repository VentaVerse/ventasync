<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

class SettingsHubController extends Controller
{
    /**
     * A browsable front door for configuration. The nav keeps its direct
     * links; this exists so someone who does not know a setting's name can
     * still find it by scanning, which a dropdown does not allow.
     */
    public function index(): View
    {
        $groups = array_filter(array_map(
            fn (array $items) => array_values(array_filter($items, fn ($d) => $this->allowed($d))),
            $this->destinations()
        ));

        return view('settings.hub', ['groups' => array_filter($groups)]);
    }

    private function allowed(array $d): bool
    {
        if (!\Route::has($d['route'])) {
            return false;
        }

        if (empty($d['permission'])) {
            return true;
        }

        $user = auth()->user();
        if (!$user) {
            return false;
        }

        // Not $user->can(): this app has no Gates or Policies registered for
        // these keys, so the default Laravel Gate would deny every one of
        // them regardless of what the user actually holds. Authorization
        // here runs through User::hasPermission(), the same method the
        // `perm:` route middleware and EnsurePermission delegate to.
        //
        // 'permission' is pipe-delimited to match the `perm:key1|key2`
        // middleware syntax on the destination's own route (any one match
        // grants access). Each value below was copied from that route's
        // actual middleware in routes/web.php at the time this was written.
        // It is hand-maintained and can drift if the route's gate changes
        // without this list being updated to match; there is no automatic
        // link between the two.
        foreach (explode('|', $d['permission']) as $key) {
            $key = trim($key);
            if ($key !== '' && $user->hasPermission($key)) {
                return true;
            }
        }

        return false;
    }

    private function destinations(): array
    {
        return [
            'Store' => [
                // IA migration (2026-08-08, Task 3), spec decision 4: the
                // once-tabbed settings page split into four real routes,
                // each its own hub destination, rather than one card that
                // dumped a visitor onto the first tab.
                ['label' => 'General', 'description' => 'Company name and address',
                 'route' => 'settings.general', 'icon' => 'building',
                 'permission' => 'view_website_settings|manage_website_settings',
                 'keywords' => ['company', 'name', 'address', 'contact']],
                ['label' => 'Website Settings', 'description' => 'Branding, timezone and activity log retention',
                 'route' => 'settings.website', 'icon' => 'globe',
                 'permission' => 'view_website_settings|manage_website_settings',
                 'keywords' => ['brand', 'logo', 'favicon', 'timezone', 'retention']],
                ['label' => 'Mail', 'description' => 'SMTP transport and test delivery',
                 'route' => 'settings.mail', 'icon' => 'mail',
                 'permission' => 'view_website_settings|manage_website_settings',
                 'keywords' => ['email', 'smtp', 'sendmail', 'transport', 'test mail']],
                ['label' => 'Maintenance', 'description' => 'The cron line every scheduled task runs from',
                 'route' => 'settings.maintenance', 'icon' => 'clock',
                 'permission' => 'view_website_settings|manage_website_settings',
                 'keywords' => ['cron', 'schedule', 'task', 'automation']],
                ['label' => 'Currencies', 'description' => 'Exchange rates and the default currency',
                 'route' => 'currencies.index', 'icon' => 'coins',
                 'permission' => 'view_website_settings|manage_website_settings',
                 'keywords' => ['exchange', 'rate', 'peso', 'usd', 'forex', 'money']],
            ],
            'Sales' => [
                ['label' => 'Order Statuses', 'description' => 'The statuses an order can move through',
                 'route' => 'order_statuses.index', 'icon' => 'receipt',
                 'permission' => 'view_order_statuses|manage_order_statuses',
                 'keywords' => ['status', 'pending', 'shipped', 'workflow']],
            ],
            'Access' => [
                ['label' => 'Users', 'description' => 'People who can sign in',
                 'route' => 'users.index', 'icon' => 'users',
                 'permission' => 'view_users|manage_users',
                 'keywords' => ['staff', 'account', 'login', 'password']],
                ['label' => 'User Groups', 'description' => 'Roles and what each one may do',
                 'route' => 'user_groups.index', 'icon' => 'shield',
                 'permission' => 'view_user_groups|manage_user_groups',
                 'keywords' => ['role', 'permission', 'access', 'group']],
            ],
            'System' => [
                ['label' => 'Extensions', 'description' => 'Install and configure add-on modules',
                 'route' => 'extensions.index', 'icon' => 'puzzle',
                 'permission' => 'view_website_settings|manage_website_settings',
                 'keywords' => ['module', 'plugin', 'addon', 'install']],
                ['label' => 'Error Log', 'description' => 'Recent application errors',
                 'route' => 'error_log.index', 'icon' => 'alert-triangle',
                 'permission' => 'view_error_log|manage_error_log',
                 'keywords' => ['log', 'exception', 'debug', 'trace']],
            ],
        ];
    }
}
