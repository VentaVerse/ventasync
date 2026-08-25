<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\Navigation;
use Illuminate\Contracts\View\View;

class SettingsHubController extends Controller
{
    /**
     * A browsable front door for configuration. The nav keeps its direct
     * links; this exists so someone who does not know a setting's name can
     * still find it by scanning, which a dropdown does not allow.
     *
     * Visibility is DERIVED, not declared: Navigation::allows() reads each
     * destination route's own `perm:` middleware, so a card can never offer a
     * page the route behind it would bounce, and changing a route's gate needs
     * no edit here. This list used to carry a hand-copied permission string
     * per card with a comment admitting it could drift; the drift is what the
     * IA migration's spec decision 7 deleted, not the comment.
     */
    public function index(): View
    {
        $groups = array_filter(array_map(
            fn (array $items) => array_values(array_filter(
                $items,
                fn (array $d) => Navigation::allows($d['route'])
            )),
            self::destinations()
        ));

        return view('settings.hub', ['groups' => $groups]);
    }

    /**
     * What the hub offers, and how each card reads. Labels, descriptions,
     * icons and search keywords are this page's presentation - content
     * somebody wrote for a human to scan - and stay declared. Only the
     * route name is wiring, and everything else about a card (does it exist,
     * may this user open it) is derived from that one string.
     *
     * Public so the breadcrumb (App\Support\Breadcrumbs) can name the card a
     * settings page sits under: the hub is the section's own index and this
     * list is the only place its cards exist, so nothing is registered twice.
     */
    public static function destinations(): array
    {
        return [
            'Store' => [
                // IA migration (2026-08-08, Task 3), spec decision 4: the
                // once-tabbed settings page split into four real routes,
                // each its own hub destination, rather than one card that
                // dumped a visitor onto the first tab.
                ['label' => 'General', 'description' => 'Company name and address',
                 'route' => 'settings.general', 'icon' => 'building',
                 'keywords' => ['company', 'name', 'address', 'contact']],
                ['label' => 'Website Settings', 'description' => 'Branding, timezone and activity log retention',
                 'route' => 'settings.website', 'icon' => 'globe',
                 'keywords' => ['brand', 'logo', 'favicon', 'timezone', 'retention']],
                ['label' => 'Mail', 'description' => 'SMTP transport and test delivery',
                 'route' => 'settings.mail', 'icon' => 'mail',
                 'keywords' => ['email', 'smtp', 'sendmail', 'transport', 'test mail']],
                ['label' => 'Maintenance', 'description' => 'The cron line every scheduled task runs from',
                 'route' => 'settings.maintenance', 'icon' => 'clock',
                 'keywords' => ['cron', 'schedule', 'task', 'automation']],
                ['label' => 'Currencies', 'description' => 'Exchange rates and the default currency',
                 'route' => 'currencies.index', 'icon' => 'coins',
                 'keywords' => ['exchange', 'rate', 'peso', 'usd', 'forex', 'money']],
            ],
            'Sales' => [
                ['label' => 'Order Statuses', 'description' => 'The statuses an order can move through',
                 'route' => 'order_statuses.index', 'icon' => 'receipt',
                 'keywords' => ['status', 'pending', 'shipped', 'workflow']],
            ],
            'Access' => [
                ['label' => 'Users', 'description' => 'People who can sign in',
                 'route' => 'users.index', 'icon' => 'users',
                 'keywords' => ['staff', 'account', 'login', 'password']],
                ['label' => 'User Groups', 'description' => 'Roles and what each one may do',
                 'route' => 'user_groups.index', 'icon' => 'shield',
                 'keywords' => ['role', 'permission', 'access', 'group']],
            ],
            'System' => [
                ['label' => 'Extensions', 'description' => 'Install and configure add-on modules',
                 'route' => 'extensions.index', 'icon' => 'puzzle',
                 'keywords' => ['module', 'plugin', 'addon', 'install']],
                ['label' => 'Error Log', 'description' => 'Recent application errors',
                 'route' => 'error_log.index', 'icon' => 'alert-triangle',
                 'keywords' => ['log', 'exception', 'debug', 'trace']],
            ],
        ];
    }
}
