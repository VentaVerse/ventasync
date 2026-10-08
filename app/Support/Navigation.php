<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;

class Navigation
{
    public static function definition(): array
    {
        return [
            ['label' => 'Dashboard', 'icon' => 'monitor', 'route' => 'dashboard',
             'keywords' => ['home', 'overview', 'summary']],

            ['label' => 'Sales', 'icon' => 'receipt', 'items' => [
                ['label' => 'Orders', 'route' => 'orders.index',
                 'keywords' => ['sale', 'customer', 'invoice', 'marketplace']],
                ['label' => 'Accounts receivable', 'route' => 'orders.payments_report',
                 'keywords' => ['payments', 'receivable', 'balance', 'owed', 'paid', 'settlement', 'remittance']],
            ]],

            ['label' => 'Fulfilment', 'icon' => 'globe', 'route' => 'channels.fulfilment',
             'keywords' => ['fulfilment', 'fulfillment', 'ship', 'pack', 'marketplace orders']],

            ['label' => 'Channels', 'icon' => 'plug', 'items' => self::channelEntries()],

            ['label' => 'Master Catalog', 'icon' => 'package', 'items' => [
                ['label' => 'Products', 'route' => 'products.index',
                 'keywords' => ['sku', 'variation', 'stock', 'price']],
                ['label' => 'Categories', 'route' => 'categories.index',
                 'keywords' => ['taxonomy', 'group']],
                ['label' => 'Manufacturers', 'route' => 'manufacturers.index',
                 'keywords' => ['brand', 'supplier']],
                ['label' => 'Reviews', 'route' => 'ext.opencart.reviews.index',
                 'keywords' => ['rating', 'feedback', 'comment']],
            ]],

            ['label' => 'Purchasing', 'icon' => 'truck', 'items' => [
                ['label' => 'Purchase Orders', 'route' => 'ext.purchasing.purchase_orders.index',
                 'keywords' => ['po', 'buy', 'restock', 'inbound']],
                ['label' => 'Vendors', 'route' => 'ext.purchasing.vendors.index',
                 'keywords' => ['supplier', 'seller', 'source']],
            ]],

            ['label' => 'Warehousing', 'icon' => 'archive', 'items' => [
                ['label' => 'Transfers', 'route' => 'ext.warehousing.transfers.index',
                 'keywords' => ['move', 'stock transfer', 'location']],
                ['label' => 'Inventory', 'route' => 'ext.warehousing.inventory.index',
                 'keywords' => ['stock', 'quantity', 'count', 'adjust']],
                ['label' => 'Locations', 'route' => 'ext.warehousing.locations.index',
                 'keywords' => ['warehouse', 'branch', 'store']],
            ]],

            ['label' => 'Reports', 'icon' => 'coins', 'items' => [
                ['label' => 'Orders', 'route' => 'ext.reports.orders',
                 'keywords' => ['revenue', 'sales report']],
                ['label' => 'Products', 'route' => 'ext.reports.products',
                 'keywords' => ['profit', 'margin', 'markup', 'cogs']],
                ['label' => 'Categories', 'route' => 'ext.reports.categories',
                 'keywords' => ['category profit']],
                ['label' => 'Fees', 'route' => 'ext.reports.fees',
                 'keywords' => ['commission', 'charge', 'marketplace fee']],
                ['label' => 'Payouts', 'route' => 'ext.reports.payouts',
                 'keywords' => ['settlement', 'remittance', 'disbursement']],
                ['label' => 'Inventory', 'route' => 'ext.reports.inventory',
                 'keywords' => ['stock value', 'on hand']],
            ]],

            ['label' => 'Finance', 'icon' => 'wallet', 'items' => [
                ['label' => 'Petty Cash', 'route' => 'ext.finance.petty_cash.index',
                 'keywords' => ['expense', 'cash', 'reimbursement', 'float']],
            ]],

            ...self::extensionEntries(),

            ['label' => 'AI Tools', 'icon' => 'sparkles', 'route' => 'ext.mcp.index',
             'keywords' => ['ai', 'assistant', 'mcp', 'claude', 'cursor', 'chatgpt']],

            ['label' => 'Settings', 'icon' => 'shield', 'route' => 'settings.hub',
             'keywords' => ['config', 'currency', 'user', 'permission', 'extension', 'order status']],
        ];
    }

    protected static function extensionEntries(): array
    {
        $entries = [];

        try {
            $navGroups = app(\App\Extensions\ExtensionManager::class)->getNavItems();

            foreach ($navGroups as $navGroup) {
                $label = trim((string) ($navGroup['group'] ?? ''));

                if ($label === '') {
                    continue;
                }

                $items = [];

                foreach ($navGroup['items'] ?? [] as $navItem) {
                    if (!isset($navItem['route']) || !is_string($navItem['route']) || $navItem['route'] === '') {
                        continue;
                    }

                    $items[] = [
                        'label' => (string) ($navItem['label'] ?? $navItem['route']),
                        'route' => $navItem['route'],
                        'params' => $navItem['params'] ?? [],
                        'permission' => isset($navItem['permission']) && $navItem['permission'] !== ''
                            ? (string) $navItem['permission']
                            : null,
                        'keywords' => $navItem['keywords'] ?? [],
                    ];
                }

                if ($items === []) {
                    continue;
                }

                $entries[] = [
                    'label' => $label,
                    'icon' => (string) ($navGroup['icon'] ?? 'square'),
                    'items' => $items,
                ];
            }
        } catch (\Throwable) {
        }

        return $entries;
    }

    public static function canAddStore(): bool
    {
        try {
            $user = auth()->user();
            foreach (app(\App\Integrations\IntegrationRegistry::class)->visibleCards($user) as $card) {
                $add = $card->addStore ?? null;
                if ($add && (! $add->permission || ($user && $user->hasPermission($add->permission)))) {
                    return true;
                }
            }
        } catch (\Throwable) {
        }

        return false;
    }

    protected static function channelEntries(): array
    {
        $items = [
            ['label' => 'All channels', 'route' => 'channels.index',
             'keywords' => ['integrations', 'status', 'connection', 'sync', 'marketplace', 'board']],
        ];

        try {
            $registry = app(\App\Integrations\IntegrationRegistry::class);
            $workspace = app(ChannelWorkspace::class);
            $user = auth()->user();

            foreach ($registry->visibleCards($user) as $card) {
                if ($card->addStore !== null && $card->stores === []) {
                    continue;
                }
                if ($workspace->hasWorkspace($card)) {
                    if ($card->stores !== []) {
                        $children = [];
                        foreach ($card->stores as $store) {
                            $storeId = explode(':', $store->id, 2)[1] ?? null;
                            $children[] = ['label' => $store->label,
                                           'route' => 'ext.'.$card->id.'.dashboard',
                                           'params' => $storeId === null ? [] : ['store' => $storeId],
                                           'keywords' => [$card->id, $store->label, 'seller center', 'orders', 'store']];
                        }
                        $items[] = ['label' => $card->name, 'children' => $children, 'keywords' => [$card->id]];
                        continue;
                    }

                    $items[] = ['label' => $card->name, 'route' => 'ext.'.$card->id.'.dashboard',
                                'keywords' => [$card->id, 'seller center', 'orders', 'listings']];
                    continue;
                }

                if ($card->stores !== []) {
                    $children = [];
                    foreach ($card->stores as $store) {
                        foreach ($store->menu as $menuItem) {
                            if ($menuItem->label === 'Orders') {
                                $children[] = ['label' => $store->label,
                                               'route' => $menuItem->routeName,
                                               'params' => $menuItem->routeParams,
                                               'keywords' => [$card->id, 'orders', 'store']];
                                break;
                            }
                        }
                    }
                    if ($children !== []) {
                        $items[] = ['label' => $card->name, 'children' => $children, 'keywords' => [$card->id]];
                    }
                    continue;
                }

                foreach ($card->menu as $menuItem) {
                    if ($menuItem->label === 'Orders') {
                        $items[] = ['label' => $card->name, 'route' => $menuItem->routeName,
                                    'params' => $menuItem->routeParams,
                                    'keywords' => [$card->id, 'orders', 'fulfilment']];
                        break;
                    }
                }
            }
        } catch (\Throwable) {
        }

        return $items;
    }

    public static function isActive(array $item): bool
    {
        $path = self::pathFor($item);

        if ($path === null) {
            return false;
        }

        $match = self::matchPath(self::currentPath());

        return $match !== null && $match['path'] === $path;
    }

    public static function matchPath(string $path): ?array
    {
        $path = self::normalisePath($path);
        $key = 'navigation.match:'.$path;
        $request = request();

        if ($request->attributes->has($key)) {
            return $request->attributes->get($key);
        }

        $best = null;

        foreach (self::definition() as $entry) {
            if (isset($entry['route'])) {
                $best = self::better($best, null, $entry, $path);
                continue;
            }

            foreach ($entry['items'] ?? [] as $item) {
                $best = self::better($best, $entry, $item, $path);
                foreach ($item['children'] ?? [] as $child) {
                    $best = self::better($best, $entry, $child, $path);
                }
            }
        }

        $request->attributes->set($key, $best);

        return $best;
    }

    private static function better(?array $best, ?array $group, array $candidate, string $path): ?array
    {
        $candidatePath = self::pathFor($candidate);

        if ($candidatePath === null || !self::covers($candidatePath, $path)) {
            return $best;
        }

        if ($best !== null && strlen($best['path']) >= strlen($candidatePath)) {
            return $best;
        }

        return ['group' => $group, 'item' => $candidate, 'path' => $candidatePath];
    }

    public static function covers(string $entryPath, string $path): bool
    {
        if ($entryPath === $path) {
            return true;
        }

        return $entryPath !== '/' && str_starts_with($path, $entryPath.'/');
    }

    public static function urlFor(array $item): ?string
    {
        if (!isset($item['route']) || !Route::has($item['route'])) {
            return null;
        }

        try {
            return route($item['route'], $item['params'] ?? []);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function pathFor(array $item): ?string
    {
        $url = self::urlFor($item);

        return $url === null ? null : self::normalisePath($url);
    }

    public static function groupForSection(string $segment): ?array
    {
        foreach (self::definition() as $entry) {
            if (isset($entry['route']) || ($entry['items'] ?? []) === []) {
                continue;
            }

            $shared = null;
            $agrees = true;

            foreach ($entry['items'] as $item) {
                $path = self::pathFor($item);

                if ($path === null) {
                    continue;
                }

                $first = explode('/', trim($path, '/'))[0] ?? '';

                if ($first === '') {
                    $agrees = false;
                    break;
                }

                if ($shared === null) {
                    $shared = $first;
                    continue;
                }

                if ($shared !== $first) {
                    $agrees = false;
                    break;
                }
            }

            if ($agrees && $shared === $segment) {
                return $entry;
            }
        }

        return null;
    }

    public static function currentPath(): string
    {
        return self::normalisePath(request()->path());
    }

    public static function normalisePath(string $path): string
    {
        if (str_contains($path, '://')) {
            $path = (string) parse_url($path, PHP_URL_PATH);
        }

        return '/'.trim($path, '/');
    }


    public static function allows(string $routeName, array $params = []): bool
    {
        if (!Route::has($routeName)) {
            return false;
        }

        $route = Route::getRoutes()->getByName($routeName);
        preg_match_all('/\{([^}?]+)\}/', $route->uri(), $matches);

        foreach ($matches[1] as $parameter) {
            if (!array_key_exists($parameter, $params)) {
                return false;
            }
        }

        $user = auth()->user();

        if (!$user) {
            return false;
        }

        $route = Route::getRoutes()->getByName($routeName);
        $action = (string) $route->getActionName();

        if (! str_contains($action, '@')) {
            return true;
        }

        [$class] = explode('@', $action);
        $catalog = app(\App\Services\PermissionCatalogue::class);
        $key = $catalog->keyForController($class);

        if ($key === null) {
            return true;
        }

        $tier = $route->defaults['permission_tier'] ?? 'view';

        return $user->hasPermission($tier . '_' . $key);
    }

    private static function itemPermissionAllows(array $item): bool
    {
        $permission = $item['permission'] ?? null;

        if ($permission === null || $permission === '') {
            return true;
        }

        $user = auth()->user();

        if (!$user) {
            return false;
        }

        foreach (explode('|', $permission) as $key) {
            if ($user->hasPermission(trim($key))) {
                return true;
            }
        }

        return false;
    }

    public static function groups(): array
    {
        $out = [];

        foreach (self::definition() as $entry) {
            if (isset($entry['route'])) {
                if (self::allows($entry['route'], $entry['params'] ?? []) && self::itemPermissionAllows($entry)) {
                    $out[] = $entry;
                }
                continue;
            }

            $items = [];
            foreach ($entry['items'] as $i) {
                if (! isset($i['route']) && ! empty($i['children'])) {
                    $children = array_values(array_filter(
                        $i['children'],
                        fn (array $c) => self::allows($c['route'], $c['params'] ?? []) && self::itemPermissionAllows($c)
                    ));
                    if ($children !== []) {
                        $items[] = ['children' => $children] + $i;
                    }
                    continue;
                }
                if (self::allows($i['route'], $i['params'] ?? []) && self::itemPermissionAllows($i)) {
                    $items[] = $i;
                }
            }

            if ($items !== []) {
                $out[] = ['label' => $entry['label'], 'icon' => $entry['icon'], 'items' => $items];
            }
        }

        return self::flattenSingles(self::mergeGroupsByLabel($out));
    }

    private static function flattenSingles(array $groups): array
    {
        return array_map(function (array $entry) {
            if (isset($entry['route']) || count($entry['items'] ?? []) !== 1) {
                return $entry;
            }

            $item = $entry['items'][0];
            if (! isset($item['route'])) {
                return $entry;
            }

            return $item + ['icon' => $entry['icon'] ?? null];
        }, $groups);
    }

    private static function mergeGroupsByLabel(array $groups): array
    {
        $merged = [];
        $indexByLabel = [];

        foreach ($groups as $entry) {
            $label = (string) $entry['label'];

            if (!isset($indexByLabel[$label])) {
                $indexByLabel[$label] = count($merged);
                $merged[] = $entry;
                continue;
            }

            $target = $indexByLabel[$label];
            $existing = $merged[$target];

            if (isset($existing['route']) && !isset($entry['route'])) {
                $entry['items'] = self::dedupeItems(
                    array_merge([['label' => $existing['label'], 'route' => $existing['route'], 'params' => $existing['params'] ?? []]], $entry['items'])
                );
                $entry['icon'] = $existing['icon'] ?? $entry['icon'];
                $merged[$target] = $entry;
                continue;
            }

            if (!isset($existing['route']) && isset($entry['route'])) {
                $merged[$target]['items'] = self::dedupeItems(array_merge(
                    [['label' => $entry['label'], 'route' => $entry['route'], 'params' => $entry['params'] ?? []]],
                    $existing['items']
                ));
                continue;
            }

            if (!isset($existing['route']) && !isset($entry['route'])) {
                $merged[$target]['items'] = self::dedupeItems(
                    array_merge($existing['items'], $entry['items'])
                );
            }
        }

        return $merged;
    }

    private static function dedupeItems(array $items): array
    {
        $seen = [];
        $out = [];

        foreach ($items as $item) {
            $key = self::itemKey($item);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $item;
            }
        }

        return $out;
    }

    private static function itemKey(array $item): string
    {
        return ($item['route'] ?? '') . '|' . json_encode($item['params'] ?? []);
    }
}
