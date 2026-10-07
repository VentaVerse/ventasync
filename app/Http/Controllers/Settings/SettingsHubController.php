<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\Navigation;
use Illuminate\Contracts\View\View;

class SettingsHubController extends Controller
{
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

    public static function destinations(): array
    {
        return [
            'Store' => [
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
                ['label' => 'Fulfilment', 'description' => 'How orders are packed before booking',
                 'route' => 'settings.fulfilment', 'icon' => 'package',
                 'keywords' => ['packing', 'pack', 'count', 'booking', 'ship', 'packer']],
            ],
            'Access' => [
                ['label' => 'Users', 'description' => 'People who can sign in',
                 'route' => 'users.index', 'icon' => 'users',
                 'keywords' => ['staff', 'account', 'login', 'password']],
                ['label' => 'User Groups', 'description' => 'Roles and what each one may do',
                 'route' => 'user_groups.index', 'icon' => 'shield',
                 'keywords' => ['role', 'permission', 'access', 'group']],
                ['label' => 'API Applications', 'description' => 'Keys for other software to read or change your data',
                 'route' => 'api_clients.index', 'icon' => 'plug',
                 'keywords' => ['api', 'token', 'mcp', 'assistant', 'claude', 'integration', 'scope']],
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
