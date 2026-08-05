<?php

namespace App\Http\Controllers;

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

        return empty($d['permission']) || auth()->user()?->can($d['permission']);
    }

    private function destinations(): array
    {
        return [
            'Store' => [
                ['label' => 'Website', 'description' => 'Company name, logo, contact details and mail',
                 'route' => 'settings.website', 'icon' => 'globe',
                 'keywords' => ['brand', 'logo', 'email', 'smtp', 'company', 'timezone']],
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
