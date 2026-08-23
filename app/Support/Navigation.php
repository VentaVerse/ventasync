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
            ['label' => 'Fulfilment', 'icon' => 'globe', 'route' => 'channels.fulfilment',
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

            // One entry per extension-declared nav group (extension.json's
            // "nav" key), e.g. the audit extension's "Audit & Monitoring".
            // Placed here, ahead of Settings, because that is what an
            // accountability/tracking feature sits beside; an extension
            // whose manifest names no group at all falls back to a Settings
            // hub card instead (SettingsHubController), never here.
            ...self::extensionEntries(),

            ['label' => 'Settings', 'icon' => 'shield', 'route' => 'settings.hub',
             'keywords' => ['config', 'currency', 'user', 'permission', 'extension', 'order status']],
        ];
    }

    /**
     * One nav group per enabled extension manifest that declares a "nav"
     * key (ExtensionManager::getNavItems()), reshaped into the same
     * {label, icon, items: [{label, route, params, permission}]} the rest of
     * definition() uses.
     *
     * Two gates apply to every item, exactly as MenuItem's does for the
     * channel workspace sidebar (ChannelWorkspace::allows()): the route's own
     * `perm:` middleware (through allows(), the same authority every other
     * entry in this tree answers to) AND the manifest's own declared
     * `permission` field, checked in groups() via itemPermissionAllows().
     * Neither gate can grant what the other refuses. The manifest's
     * GROUP-level `permission` (e.g. "manage_audit") is metadata for the
     * permission editor's grouping, not a nav gate: the item's own
     * `permission` plus its route's real middleware are what decide
     * visibility, so a link is never hidden from someone the route would
     * actually let through.
     *
     * A manifest with no "nav" key, no items, or only items pointing at
     * dead or disabled-extension routes contributes nothing: an empty
     * group is dropped by groups() the same way an empty Channels or
     * Catalogue group would be.
     */
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
            // A failed manager (manifest JSON error, extensions table
            // missing mid-migration) must not take down every page that
            // renders the nav. It degrades to no extension groups at all.
        }

        return $entries;
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
            ['label' => 'All channels', 'route' => 'channels.index',
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
                            // $store->id is the colon key (`venta:2`); the
                            // dashboard route wants the bare store number.
                            // Each channel's dashboard is its own route now
                            // (ext.{id}.dashboard), so the entry can no
                            // longer point at a channel that has none.
                            $storeId = explode(':', $store->id, 2)[1] ?? null;
                            $items[] = ['label' => $card->name.' '.$store->label,
                                        'route' => 'ext.'.$card->id.'.dashboard',
                                        'params' => $storeId === null ? [] : ['store' => $storeId],
                                        'keywords' => [$card->id, $store->label, 'seller center', 'orders', 'store']];
                        }
                        continue;
                    }

                    $items[] = ['label' => $card->name, 'route' => 'ext.'.$card->id.'.dashboard',
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
     * Active state by LONGEST URL PREFIX (IA migration spec decision 7).
     *
     * An entry is active when the current URL sits at or below the entry's own
     * URL, and no other entry reaches further down the same path. Two things
     * made this replace the old routeIs() check:
     *
     *  1. routeIs() is exact, so it went dark one level deep. Tasks 1-4 made
     *     /catalog/products/9/edit express its own ancestry; the sidebar
     *     should read it rather than lose the operator's place on every edit
     *     screen.
     *  2. Route NAMES are frozen and deliberately incoherent with the URLs
     *     (spec decision 1), so a name is no longer evidence of where a page
     *     sits. /settings/order-statuses is the case that proves it: the name
     *     is order_statuses.index and the legacy sidebar therefore opened
     *     SALES on it, while the URL - and the owner's ruling - say Settings.
     *
     * Prefix matching is segment-aware, never a bare str_starts_with: without
     * that, /channels/shopee would claim /channels/shopee-legacy, and
     * /sales/order would claim /sales/order-statuses.
     *
     * The parameter comparison the old implementation needed is gone because
     * the entry's params are already baked into the URL this compares:
     * every Venta seller center is ext.venta.dashboard, but one is
     * /channels/venta/2 and another /channels/venta/3.
     */
    public static function isActive(array $item): bool
    {
        $path = self::pathFor($item);

        if ($path === null) {
            return false;
        }

        $match = self::matchPath(self::currentPath());

        return $match !== null && $match['path'] === $path;
    }

    /**
     * The nav entry whose URL is the longest prefix of $path, with the group
     * it belongs to (null for a top-level entry that is itself a page).
     *
     * Ties are impossible by construction: two entry paths that both cover the
     * same path and have the same length are the same string, so first-wins
     * only ever applies to duplicate entries.
     *
     * Walks definition() rather than groups() so the answer does not change
     * with the viewer. A page the current user cannot reach cannot be the page
     * they are on, and making "which section is this URL in" depend on
     * permissions would give two operators different trails for the same URL.
     *
     * Memoised on the request: the sidebar asks this once per item for the
     * link and again for its group's open state, and the breadcrumb asks again
     * on the same render. Request attributes rather than a static, so nothing
     * leaks between tests sharing a process.
     *
     * @return array{group: array|null, item: array, path: string}|null
     */
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
            }
        }

        $request->attributes->set($key, $best);

        return $best;
    }

    /**
     * $candidate wins over $best when it covers $path and reaches deeper.
     *
     * @param array{group: array|null, item: array, path: string}|null $best
     * @return array{group: array|null, item: array, path: string}|null
     */
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

    /**
     * True when $path is $entryPath or lives beneath it, comparing whole
     * segments. The root entry (Dashboard, at '/') covers only itself: a
     * str_starts_with on '/' would otherwise make it the ancestor of the
     * entire application and win every page that matched nothing else.
     */
    public static function covers(string $entryPath, string $path): bool
    {
        if ($entryPath === $path) {
            return true;
        }

        return $entryPath !== '/' && str_starts_with($path, $entryPath.'/');
    }

    /**
     * The URL a nav entry points at, or null when it points nowhere this
     * install can generate: a group heading (no route), a route belonging to a
     * disabled extension, or a route whose required parameters the entry does
     * not supply (allows() rejects that class of entry too - see its comment).
     *
     * Absolute, exactly as route() renders it, because this is what ends up in
     * an href and every other link in the app is generated the same way.
     */
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

    /**
     * The same destination as a URL PATH, which is what prefix matching
     * compares: scheme, host and port say nothing about where a page sits in
     * the information architecture.
     */
    public static function pathFor(array $item): ?string
    {
        $url = self::urlFor($item);

        return $url === null ? null : self::normalisePath($url);
    }

    /**
     * The group whose items all live under a first URL segment, e.g. 'catalog'
     * -> the Catalogue group. Used for a page one level deeper than anything
     * the nav lists, so its trail still names the section it stands in.
     *
     * Returns null when the group's items disagree about their first segment,
     * because then the segment is not that group's section at all.
     *
     * Only reached when matchPath() found nothing, which is why Channels never
     * arrives here in practice even though its entries do share /integrations:
     * its "All channels" entry sits at /integrations itself and therefore
     * covers every path beneath it, so matchPath always answers first. Channel
     * pages take a different road again (Breadcrumbs::channelTrail).
     */
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

    /** The path of the request being served, in the same shape pathFor returns. */
    public static function currentPath(): string
    {
        return self::normalisePath(request()->path());
    }

    /**
     * One canonical shape for every path this class compares: a single leading
     * slash, no trailing slash, and '/' for the root. Accepts a full URL too,
     * so a caller holding a generated href does not have to parse it first.
     */
    public static function normalisePath(string $path): string
    {
        if (str_contains($path, '://')) {
            $path = (string) parse_url($path, PHP_URL_PATH);
        }

        return '/'.trim($path, '/');
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

        /* THE SIDEBAR MIRRORS THE DOOR, OR IT LIES.
         *
         * This used to parse the route's own perm: middleware, which was the
         * gate before EnsureControllerPermission replaced it - so the moment
         * the perm: arguments came off the routes, every extension link
         * vanished for everyone. The authority now is the same one the door
         * uses: the route's controller derives a key from the catalogue, a
         * nav link is a GET so it needs the view tier, and the door's
         * legacy fallback applies here too so a pre-rebuild group keeps its
         * links exactly as long as it keeps its access. */
        $route = Route::getRoutes()->getByName($routeName);
        $action = (string) $route->getActionName();

        if (! str_contains($action, '@')) {
            return true; // closure routes carry no derivable gate
        }

        [$class] = explode('@', $action);
        $catalogue = app(\App\Services\PermissionCatalogue::class);
        $key = $catalogue->keyForController($class);

        if ($key === null) {
            return true; // never gated: dashboard, hubs that gate per item
        }

        $tier = $route->defaults['permission_tier'] ?? 'view';

        if ($user->hasPermission($tier . '_' . $key)) {
            return true;
        }

        foreach ($catalogue->legacyKeysFor($class)[$tier] ?? [] as $legacy) {
            if ($user->hasPermission($legacy)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The second gate a manifest-declared item can carry alongside the
     * route's own perm: middleware (see extensionEntries()). Core
     * definition() entries never set this key, so this is a no-op for
     * every one of them: absent or empty means "no extra gate".
     */
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

    /**
     * The tree a view should render: existing routes only, permitted only,
     * and groups with no surviving children removed entirely.
     */
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

            $items = array_values(array_filter(
                $entry['items'],
                fn (array $i) => self::allows($i['route'], $i['params'] ?? []) && self::itemPermissionAllows($i)
            ));

            if ($items !== []) {
                $out[] = ['label' => $entry['label'], 'icon' => $entry['icon'], 'items' => $items];
            }
        }

        return self::mergeGroupsByLabel($out);
    }

    /**
     * Fold groups that share a label into one.
     *
     * definition() carries both the core group and the one an extension
     * contributes under the same name, so an admin with every permission saw
     * "Purchasing", "Warehousing", "Reports" and "Petty Cash" twice each in
     * the sidebar. Merging here rather than in definition() keeps each source
     * free to declare its own entries without knowing what else exists, and
     * fixes every consumer at once: the sidebar, the command palette and
     * search all read groups().
     *
     * The first occurrence keeps its position and icon; later ones donate
     * their items. Items are de-duplicated on route plus params, so a link
     * declared by both sides appears once.
     *
     * @param  array<int, array<string, mixed>>  $groups
     * @return array<int, array<string, mixed>>
     */
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

            // Same label held by a bare link and by a group. The group wins the
            // slot and the link becomes its first child, which is how "Petty
            // Cash" the page and "Petty Cash" the section stop being two
            // separate things in the sidebar.
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

            // Two groups: the first keeps its position, the second donates.
            if (!isset($existing['route']) && !isset($entry['route'])) {
                $merged[$target]['items'] = self::dedupeItems(
                    array_merge($existing['items'], $entry['items'])
                );
            }

            // Two bare links with one label and one destination: the first wins.
        }

        return $merged;
    }

    /**
     * Drop items that resolve to the same destination, keeping the first.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
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

    /**
     * Identity for a nav item: its route and the params that resolve it.
     *
     * @param  array<string, mixed>  $item
     */
    private static function itemKey(array $item): string
    {
        return ($item['route'] ?? '') . '|' . json_encode($item['params'] ?? []);
    }
}
