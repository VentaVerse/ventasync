<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;

/**
 * Single source of truth for the global navigation.
 *
 * Permissions are NOT hand-maintained here. They are read from each route's
 * own `perm:` middleware at runtime, so this file cannot drift out of step
 * with routes/web.php. A previous hand-maintained list in SettingsHubController
 * had to carry a comment warning about exactly that drift.
 */
class Navigation
{
    /**
     * The raw tree, before route-existence and permission filtering.
     * Extension routes (`ext.` prefix) exist only while the extension is
     * enabled, which is a real state, not a hypothetical.
     */
    public static function definition(): array
    {
        return [
            ['label' => 'Dashboard', 'icon' => 'monitor', 'route' => 'dashboard',
             'keywords' => ['home', 'overview', 'summary']],

            // Order Statuses is configuration, not a daily operation, so it
            // lives as a card in the Settings hub (SettingsHubController)
            // rather than here. Per the owner: the nav shows operations.
            ['label' => 'Sales', 'icon' => 'receipt', 'items' => [
                ['label' => 'Orders', 'route' => 'orders.index',
                 'keywords' => ['sale', 'customer', 'invoice', 'marketplace']],
                ['label' => 'Payments', 'route' => 'orders.payments_report',
                 'keywords' => ['paid', 'settlement', 'remittance']],
            ]],

            // Top level, not inside Sales or Channels, because it is neither:
            // Sales/Orders is the consolidated ERP record (an information
            // page) and Channels leads into one marketplace at a time. This
            // is the daily working surface across all of them, so it sits
            // between the two at the same level. Named Fulfilment because
            // "Orders" already means the information page one group above.
            ['label' => 'Fulfilment', 'icon' => 'globe', 'route' => 'integrations.orders',
             'keywords' => ['fulfilment', 'fulfillment', 'ship', 'pack', 'marketplace orders']],

            ['label' => 'Channels', 'icon' => 'plug', 'items' => self::channelEntries()],

            ['label' => 'Catalogue', 'icon' => 'package', 'items' => [
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

            ['label' => 'Petty Cash', 'icon' => 'wallet', 'route' => 'ext.pettycash.index',
             'keywords' => ['expense', 'cash', 'reimbursement', 'float']],

            ['label' => 'Settings', 'icon' => 'shield', 'route' => 'settings.hub',
             'keywords' => ['config', 'currency', 'user', 'permission', 'extension', 'order status']],
        ];
    }

    /**
     * The Channels group: one entry per marketplace the current user can see,
     * derived from the IntegrationRegistry so this list and the /integrations
     * board can never disagree about what exists.
     *
     * A converted channel (one whose card declares grouped menu items, see
     * ChannelWorkspace::hasWorkspace()) links to its seller center Overview.
     * An unconverted one links straight to its orders page, the old nav
     * behaviour, and upgrades automatically when its menu gets grouped.
     * Multi-store channels get one entry per store because their routes are
     * store-scoped; there is no storeless page to land on.
     *
     * visibleCards() has already applied the card permission, and allows()
     * still checks each destination route's own perm: middleware, so both
     * gates hold here exactly as they do in the workspace sidebar.
     */
    protected static function channelEntries(): array
    {
        $items = [
            ['label' => 'All channels', 'route' => 'integrations.index',
             'keywords' => ['integrations', 'status', 'connection', 'sync', 'marketplace', 'board']],
        ];

        try {
            $registry = app(\App\Integrations\IntegrationRegistry::class);
            $workspace = app(ChannelWorkspace::class);
            $user = auth()->user();

            foreach ($registry->visibleCards($user) as $card) {
                if ($workspace->hasWorkspace($card)) {
                    // A converted multi-store channel has no storeless page,
                    // so it offers one seller center per store, keyed the way
                    // StoreLink ids already are. The card name stays in the
                    // label because store names repeat across channels: a
                    // seller running "Shop A" on both Venta and
                    // OpenCart would otherwise get two identical entries.
                    if ($card->stores !== []) {
                        foreach ($card->stores as $store) {
                            // The URL carries the store in slash grammar
                            // (`venta/2`); $store->id stays the colon key
                            // everywhere else. See ChannelWorkspace::pathKey().
                            $items[] = ['label' => $card->name.' '.$store->label,
                                        'route' => 'integrations.workspace',
                                        'params' => ['channel' => ChannelWorkspace::pathKey($store->id)],
                                        'keywords' => [$card->id, $store->label, 'seller center', 'orders', 'store']];
                        }
                        continue;
                    }

                    $items[] = ['label' => $card->name, 'route' => 'integrations.workspace',
                                'params' => ['channel' => $card->id],
                                'keywords' => [$card->id, 'seller center', 'orders', 'listings']];
                    continue;
                }

                if ($card->stores !== []) {
                    foreach ($card->stores as $store) {
                        foreach ($store->menu as $menuItem) {
                            if ($menuItem->label === 'Orders') {
                                $items[] = ['label' => $card->name.' '.$store->label,
                                            'route' => $menuItem->routeName,
                                            'params' => $menuItem->routeParams,
                                            'keywords' => [$card->id, 'orders', 'store']];
                                break;
                            }
                        }
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
            // A failed registry (missing table mid-migration, disabled
            // extension throwing in boot) must not take down every page that
            // renders the nav. The group degrades to "All channels" alone.
        }

        return $items;
    }

    /**
     * Param-aware active check. request()->routeIs() alone cannot tell two
     * entries on the same route apart: every seller center is
     * integrations.workspace and every Venta store is ext.venta.orders.index,
     * so without comparing the bound parameters, opening one store would
     * highlight them all.
     */
    public static function isActive(array $item): bool
    {
        if (!request()->routeIs($item['route'])) {
            return false;
        }

        foreach ($item['params'] ?? [] as $key => $value) {
            if ((string) request()->route($key) !== (string) $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * Read the pipe-delimited permission from the route's own `perm:`
     * middleware. Returns null for an auth-only route or an unknown one.
     */
    public static function permissionFor(string $routeName): ?string
    {
        $route = Route::getRoutes()->getByName($routeName);

        if (!$route) {
            return null;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'perm:')) {
                return substr($middleware, 5);
            }
        }

        return null;
    }

    /**
     * Same any-match parsing EnsurePermission uses. Never can(): this app
     * registers no Gates or Policies, so can() is always false.
     */
    public static function allows(string $routeName, array $params = []): bool
    {
        if (!Route::has($routeName)) {
            return false;
        }

        // Every REQUIRED URI parameter must be supplied by the entry, or
        // route() throws UrlGenerationException at render time, taking down
        // every page that includes the sidebar rather than just hiding one
        // link. {store} is required; {store?} is not. Channel entries carry
        // their params ({channel}, {store}) in the definition, so a
        // parameterised route is fine as long as the entry covers it.
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

        $permission = self::permissionFor($routeName);

        if ($permission === null) {
            return true;
        }

        foreach (explode('|', $permission) as $key) {
            if ($user->hasPermission(trim($key))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The tree a view should render: existing routes only, permitted only,
     * and groups with no surviving children removed entirely.
     */
    public static function groups(): array
    {
        $out = [];

        foreach (self::definition() as $entry) {
            if (isset($entry['route'])) {
                if (self::allows($entry['route'], $entry['params'] ?? [])) {
                    $out[] = $entry;
                }
                continue;
            }

            $items = array_values(array_filter(
                $entry['items'],
                fn (array $i) => self::allows($i['route'], $i['params'] ?? [])
            ));

            if ($items !== []) {
                $out[] = ['label' => $entry['label'], 'icon' => $entry['icon'], 'items' => $items];
            }
        }

        return $out;
    }
}
