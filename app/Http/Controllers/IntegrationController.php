<?php

namespace App\Http\Controllers;

use App\Integrations\IntegrationRegistry;
use App\Support\ChannelWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class IntegrationController extends Controller
{
    /**
     * Channels whose orders content has been extracted into a shared
     * service+partial (build(Request): array, plus an ext-{id}::orders._panel
     * view) and can therefore render in place on this hub, the same way
     * Shopee's ShopeeOrdersPanel does. Every other channel's card still
     * navigates to its own seller-center orders page instead. Adding the
     * next converted channel is exactly one entry here: the class must
     * expose build(Request $request): array shaped like ShopeeOrdersPanel's
     * (feeding straight into ext-{id}::orders._panel, which owns
     * $panelBaseUrl/$panelHiddenParams), and the channel's own
     * orders.blade.php wrapper is added to resources/views/integrations/
     * orders.blade.php's per-channel wrapper markup (each channel's page JS
     * binds to its own root id and, for Lazada, page-level data attributes
     * that do not flow through the panel service).
     *
     * Keyed by BASE channel id, not by tab id. A multi-store channel
     * registers one tab per store with an id of "venta:2", and all of that
     * channel's stores share one panel class; the store is parsed off the
     * key and handed to build() as a second argument. See panelFor().
     */
    private const ORDER_PANEL_SERVICES = [
        'shopee'   => \Extensions\shopee\Services\ShopeeOrdersPanel::class,
        'lazada'   => \Extensions\lazada\Services\LazadaOrdersPanel::class,
        'tiktok'   => \Extensions\tiktok\Services\TikTokOrdersPanel::class,
        'venta'    => \Extensions\venta\Services\VentaOrdersPanel::class,
        'opencart' => \Extensions\opencart\Services\OpenCartOrdersPanel::class,
    ];

    /**
     * Which of those channels scope their panel to one store, and therefore
     * expect build(Request, int $storeId) and a tab id carrying a store
     * suffix. Declared rather than guessed from the key's shape, so a
     * single-store channel can never be handed a store and a multi-store one
     * can never be rendered without one.
     */
    private const ORDER_PANEL_STORE_SCOPED = ['venta', 'opencart'];

    /**
     * The panel class and store id for an order-tab id, or null when that
     * tab has no panel and therefore still navigates to its own seller
     * center.
     *
     * The tab id is the authority on the store, not the query string. This
     * is only ever called with an id read back off a tab that
     * visibleOrderTabs() returned, so by the time a store number reaches
     * here it has already been through that gate: the registry only builds a
     * tab for a store that exists and is enabled, and only returns it to a
     * user holding that channel's orders permission. A hand-typed
     * channel=venta:999 matches no tab, so it never reaches this method and
     * falls back to Summary.
     *
     * @return array{0: class-string, 1: int|null}|null
     */
    private function panelFor(string $tabId): ?array
    {
        $base = $tabId;
        $storeId = null;

        if (str_contains($tabId, ':')) {
            [$base, $suffix] = explode(':', $tabId, 2);
            // ctype_digit rather than is_numeric: "2e3" and " 2" are numeric
            // and are not store ids. A suffix that is not a plain positive
            // integer means this is not a shape we understand, and guessing
            // at it would be how one store's panel ends up showing another
            // store's orders.
            if ($suffix === '' || !ctype_digit($suffix) || (int) $suffix < 1) {
                return null;
            }
            $storeId = (int) $suffix;
        }

        $class = self::ORDER_PANEL_SERVICES[$base] ?? null;

        if ($class === null) {
            return null;
        }

        // A store suffix on a single-store channel names nothing, and a
        // multi-store channel with no suffix names no page: both are
        // malformed rather than defaults, so neither renders a panel.
        $wantsStore = in_array($base, self::ORDER_PANEL_STORE_SCOPED, true);

        if ($wantsStore !== ($storeId !== null)) {
            return null;
        }

        return [$class, $storeId];
    }

    /**
     * Integrations status board. One card per channel, one card per store
     * for the multi-store channels (Venta, OpenCart, Shopify), answering
     * "is everything healthy, and where do I need to go" without a trip
     * through each channel's own settings page.
     *
     * Health data is read straight from each channel's *_settings table
     * (and, where api logging is switched on, its *_api_logs table) rather
     * than through a shared contract, because the fields genuinely differ
     * per channel and forcing a common shape would mean inventing numbers
     * for channels that don't track them. Where a figure truly isn't
     * tracked (no column, or logging switched off) the value stays null so
     * the view renders "not tracked" instead of a fabricated zero.
     */
    public function index(Request $request, IntegrationRegistry $registry, ChannelWorkspace $workspace)
    {
        $user = $request->user();
        $cards = $registry->visibleCards($user);

        $now = Carbon::now();
        $staleAfter = $now->copy()->subHours(24);
        $expiringWithin = $now->copy()->addDays(7);

        // Resolve a menu item's URL by its visible label, applying the same
        // per-item permission gate the existing Integrations views already
        // apply to $card->menu / $store->menu. Returns null when the item
        // isn't defined or the user can't see it.
        $menuUrl = function (array $menu, string $label) use ($user) {
            foreach ($menu as $item) {
                if ($item->label !== $label) {
                    continue;
                }
                if ($item->permission && (!$user || !$user->hasPermission($item->permission))) {
                    continue;
                }
                return route($item->routeName, $item->routeParams);
            }
            return null;
        };

        // state = setup when the token is missing/expired, attention when
        // it expires within 7 days or the last sync is older than 24h,
        // connected otherwise. $hasToken === null means token presence
        // itself isn't tracked for this channel, so only sync freshness
        // decides between attention and connected.
        $deriveState = function (?bool $hasToken, ?Carbon $tokenExpiresAt, ?Carbon $lastSyncAt) use ($staleAfter, $expiringWithin) {
            if ($hasToken === false) {
                return 'setup';
            }
            if ($tokenExpiresAt && $tokenExpiresAt->isPast()) {
                return 'setup';
            }

            $needsAttention = ($tokenExpiresAt && $tokenExpiresAt->lessThanOrEqualTo($expiringWithin))
                || (!$lastSyncAt || $lastSyncAt->lessThan($staleAfter));

            return $needsAttention ? 'attention' : 'connected';
        };

        $channels = [];

        foreach ($cards as $card) {
            $baseId = $card->id;
            $entries = [];

            // Only channels that have actually been converted get a workspace
            // link: a card qualifies when it declares at least one menu item
            // with a group, which is exactly what the conversion does. Today
            // that is Shopee alone. Every other channel keeps its existing
            // destination, which matters most for OpenCart and Shopify, whose
            // real destinations live on their per-store cards and would be
            // unreachable from a workspace built off the top card.
            $hasWorkspace = $workspace->hasWorkspace($card);

            if (in_array($baseId, ['shopee', 'lazada', 'tiktok'], true)) {
                // Single-store OAuth marketplaces: live/sandbox creds live in
                // parallel columns on one settings row, selected by `mode`.
                $row = DB::table($baseId . '_settings')->first();
                $isSandbox = ($row?->mode ?? 'live') === 'sandbox';
                $hasToken = $row ? !empty($isSandbox ? $row->sandbox_access_token : $row->access_token) : false;
                $expiresRaw = $row ? ($isSandbox ? $row->sandbox_expires_at : $row->expires_at) : null;
                $lastSyncRaw = $row?->last_order_sync_at;
                $loggingOn = (bool) ($row?->api_logging ?? false);

                $entries[] = [
                    'key' => $baseId,
                    'workspaceKey' => $baseId,
                    'label' => $card->name,
                    'store' => null,
                    'hasToken' => $hasToken,
                    'tokenExpiresAt' => $expiresRaw ? Carbon::parse($expiresRaw) : null,
                    'lastSyncAt' => $lastSyncRaw ? Carbon::parse($lastSyncRaw) : null,
                    // api_logging is off in this sandbox for every channel, so
                    // this is null (not tracked) today, not a fabricated zero.
                    'errors24h' => $loggingOn
                        ? DB::table($baseId . '_api_logs')->where('created_at', '>=', $staleAfter)->where('ok', 0)->count()
                        : null,
                    'menu' => $card->menu,
                ];
            } elseif (in_array($baseId, ['venta', 'opencart', 'shopify', 'pedallion'], true)) {
                // Multi-store (Venta/OpenCart/Shopify) or single-store
                // (Pedallion) API-key integrations: no token expiry column
                // exists on any of these tables, so tokenExpiry always
                // reads "not tracked" here, which is a real schema gap, not
                // an oversight, and is recorded as a follow-up.
                $tokenColumn = match ($baseId) {
                    'venta' => 'api_token',
                    'opencart' => 'api_key',
                    'shopify' => 'access_token',
                    'pedallion' => 'api_key',
                };
                $stores = $card->stores ?: [null];

                foreach ($stores as $store) {
                    $storeId = $store ? (int) substr($store->id, strpos($store->id, ':') + 1) : null;
                    $row = $storeId
                        ? DB::table($baseId . '_settings')->where('id', $storeId)->first()
                        : DB::table($baseId . '_settings')->first();

                    $errors24h = null;
                    if ($row && $baseId === 'venta') {
                        $errors24h = ((bool) ($row->api_logging ?? false))
                            ? DB::table('venta_api_logs')->where('venta_setting_id', $row->id)->where('created_at', '>=', $staleAfter)->where('ok', 0)->count()
                            : null;
                    } elseif ($row && $baseId === 'shopify') {
                        $errors24h = ((bool) ($row->api_logging ?? false))
                            ? DB::table('shopify_api_logs')->where('shopify_setting_id', $row->id)->where('created_at', '>=', $staleAfter)->where('ok', 0)->count()
                            : null;
                    } elseif ($row && $baseId === 'opencart') {
                        // opencart_sync_log has no logging on/off switch, so it
                        // is always written by every sync run, so a zero
                        // here is a real "no failures", not an absent metric.
                        $errors24h = DB::table('opencart_sync_log')->where('opencart_setting_id', $row->id)->where('created_at', '>=', $staleAfter)->where('status', 'failed')->count();
                    } elseif ($row && $baseId === 'pedallion') {
                        $errors24h = ((bool) ($row->logging_enabled ?? false))
                            ? DB::table('pedallion_api_logs')->where('created_at', '>=', $staleAfter)->where('status_code', '>=', 400)->count()
                            : null;
                    }

                    $lastSyncRaw = $row?->last_order_sync_at;

                    $entries[] = [
                        'key' => $store->id ?? $baseId,
                        // The per-store workspace this row opens
                        // ("venta:2"). Null when the channel has no stores
                        // configured yet: there is no store workspace to
                        // open, so that row keeps sending the operator to
                        // the module page, where a store can be created.
                        'workspaceKey' => $store->id ?? null,
                        'label' => $card->name,
                        'store' => $store->label ?? null,
                        'hasToken' => $row ? !empty($row->{$tokenColumn}) : false,
                        'tokenExpiresAt' => null,
                        'lastSyncAt' => $lastSyncRaw ? Carbon::parse($lastSyncRaw) : null,
                        'errors24h' => $errors24h,
                        'menu' => $store->menu ?? $card->menu,
                        'storeStatus' => $store->status ?? null,
                    ];
                }
            } else {
                // Unrecognized future channel, so degrade to "nothing tracked"
                // rather than guessing at a schema nobody has inspected yet.
                $entries[] = [
                    'key' => $baseId,
                    'workspaceKey' => $baseId,
                    'label' => $card->name,
                    'store' => null,
                    'hasToken' => null,
                    'tokenExpiresAt' => null,
                    'lastSyncAt' => null,
                    'errors24h' => null,
                    'menu' => $card->menu,
                ];
            }

            foreach ($entries as $entry) {
                $state = (($entry['storeStatus'] ?? null) === 'setup_pending')
                    ? 'setup'
                    : $deriveState($entry['hasToken'], $entry['tokenExpiresAt'], $entry['lastSyncAt']);

                $ordersUrl = $menuUrl($entry['menu'], 'Orders');

                $channels[] = [
                    'key' => $entry['key'],
                    'label' => $entry['label'],
                    'store' => $entry['store'],
                    'state' => $state,
                    'lastSync' => $entry['lastSyncAt']?->diffForHumans(),
                    'tokenExpiry' => $entry['tokenExpiresAt']?->diffForHumans(),
                    'errors24h' => $entry['errors24h'],
                    // The channel workspace when this channel has one
                    // (Shopee, Lazada and TikTok, plus every configured
                    // Venta and OpenCart store), otherwise the module page
                    // and never a channel's Settings item. Settings is only one entry in
                    // that channel's full menu (Categories, Product Groups,
                    // Logistics, Reviews, etc. also live there) - preferring
                    // it here meant any channel with a Settings item made
                    // the rest of its own menu unreachable from this board,
                    // since integrations.module is otherwise never linked
                    // once the old full-menu card view was replaced by this
                    // one.
                    //
                    // A multi-store channel's rows each open their OWN store
                    // workspace ("venta:2"), because every one of its pages
                    // is store-scoped and a storeless key has nothing to
                    // show.
                    'enterUrl' => ($hasWorkspace && $entry['workspaceKey'] !== null)
                        ? route('integrations.workspace', ['channel' => $entry['workspaceKey']])
                        : route('integrations.module', ['module' => $baseId]),
                    'actionLabel' => $ordersUrl ? 'View orders' : null,
                    'actionUrl' => $ordersUrl,
                ];
            }
        }

        return view('integrations.board', compact('channels'));
    }

    /**
     * Dedicated sub-page for one module, lists all configured stores
     * with breathing room. Only useful for multi-store modules; for
     * single-store modules it just shows the same card content.
     */
    public function module(Request $request, IntegrationRegistry $registry, string $module)
    {
        $card = collect($registry->visibleCards($request->user()))
            ->firstWhere('id', $module);

        if ($card === null) {
            abort(404);
        }

        return view('integrations.module', compact('card'));
    }

    /**
     * The Fulfilment surface. Card strip of every fulfillable marketplace on
     * top, then either the aggregate Summary (the default) or one channel's
     * own orders panel rendered in place.
     *
     * Channel selection is a query param, `?channel={tabId}`, where tabId is
     * an OrderTab id ("shopee", "venta:2"). A value is valid only if it
     * matches a tab in visibleOrderTabs(), which has already applied the
     * enabled gate and the tab's own permission. That membership test IS the
     * authorisation check: an id this user cannot see is not in the list, so
     * it cannot be selected. An invalid, empty or unauthorised value falls
     * back to Summary silently rather than erroring, because a stale bookmark
     * or a revoked permission should land the operator on a working page.
     *
     * Shopee and Lazada render their orders in place today (spec D4, round
     * 2). Every other channel's card navigates to that channel's own orders
     * page until its panel is extracted the same way, so a valid id outside
     * self::ORDER_PANEL_SERVICES still shows Summary here.
     */
    public function orders(Request $request, IntegrationRegistry $registry)
    {
        $user = $request->user();
        $tabs = $registry->visibleOrderTabs($user);

        // Empty state, no fulfillable surfaces visible to this user
        if (empty($tabs)) {
            return view('integrations.orders', [
                'hasMarketplaces' => false,
                'orderTabs' => [],
                'selectedChannel' => null,
                'selectedChannelLabel' => null,
                'topProducts' => [],
                'recentOrders' => [],
            ]);
        }

        $channel = (string) $request->query('channel', '');
        $selectedTab = null;
        foreach ($tabs as $tab) {
            if ($tab->id === $channel) {
                $selectedTab = $tab;
                break;
            }
        }

        // When a channel panel is rendering, the Summary section never
        // appears, so its leaderboard and activity feed would be one
        // topProductsToday and one recentOrders query per visible tab,
        // computed and thrown away. A six-channel seller pays that on every
        // filter click inside the panel. Skip the assembly entirely and hand
        // the view the empty arrays its Summary branch will not read.
        $selectedPanel = $selectedTab !== null ? $this->panelFor($selectedTab->id) : null;
        $renderingPanel = $selectedPanel !== null;

        // Combined top-products leaderboard. Each tab returns its own top
        // products (scoped per store for multi-store marketplaces); we merge
        // by SKU, sum qty + revenue, and remember which tabs contributed so
        // the leaderboard row can show small per-tab dots next to the SKU.
        $merged = [];
        foreach ($renderingPanel ? [] : $tabs as $tab) {
            foreach ($tab->topProductsToday(10) as $tp) {
                $key = $tp->sku;
                if (!isset($merged[$key])) {
                    $merged[$key] = [
                        'sku' => $tp->sku,
                        'name' => $tp->name,
                        'imageUrl' => $tp->imageUrl,
                        'qtySold' => 0,
                        'revenue' => 0.0,
                        'contributors' => [],
                    ];
                }
                $merged[$key]['qtySold'] += $tp->qtySold;
                $merged[$key]['revenue'] += $tp->revenue;
                if ($tp->imageUrl && empty($merged[$key]['imageUrl'])) {
                    $merged[$key]['imageUrl'] = $tp->imageUrl;
                }
                $merged[$key]['contributors'][] = [
                    'label' => $tab->label,
                    'accent' => $tab->accent,
                    'qty' => $tp->qtySold,
                ];
            }
        }
        usort($merged, fn ($a, $b) => $b['qtySold'] <=> $a['qtySold']);
        $topProducts = array_slice(array_values($merged), 0, 10);

        // Combined recent activity feed, newest first across all tabs.
        $recent = [];
        foreach ($renderingPanel ? [] : $tabs as $tab) {
            foreach ($tab->recentOrders(5) as $ro) {
                $recent[] = [
                    'order' => $ro,
                    'tab' => $tab,
                ];
            }
        }
        usort($recent, function ($a, $b) {
            $aTs = $a['order']->orderedAt?->getTimestamp() ?? 0;
            $bTs = $b['order']->orderedAt?->getTimestamp() ?? 0;
            return $bTs <=> $aTs;
        });
        $recentOrders = array_slice($recent, 0, 15);

        $data = [
            'hasMarketplaces' => true,
            'orderTabs' => $tabs,
            'selectedChannel' => null,
            'selectedChannelBase' => null,
            'selectedChannelLabel' => null,
            'topProducts' => $topProducts,
            'recentOrders' => $recentOrders,
            // Every tab id in this list gets an in-place link on the card
            // strip (view keys off membership, not a per-channel literal);
            // every other tab still links out to its own orders page.
            //
            // Computed from the visible tabs rather than from the map's own
            // keys, because a multi-store channel's tab ids carry a store
            // suffix ("venta:2") and the map is keyed by base id. The strip
            // only ever iterates these same tabs, so nothing is lost by
            // narrowing the list to them.
            'panelChannels' => array_values(array_filter(
                array_map(fn ($tab) => $tab->id, $tabs),
                fn (string $id) => $this->panelFor($id) !== null
            )),
        ];

        // A converted channel's orders content is a shared panel (service +
        // partial) used by both its seller center and this page. The panel
        // builds every one of its own links from the base URL and hidden
        // params handed in here, so segment tabs, filters and pagination all
        // stay on /integrations/orders and keep carrying channel={id}.
        //
        // The class is read from the map rather than imported at the top of
        // this file: it lives in an extension, and core should not carry a
        // top-level `use` for a folder that can be absent. Reaching this
        // line means the tab was visible, which means the extension is
        // enabled and its provider booted.
        //
        // For a multi-store channel the store number came off the tab's own
        // id, which is the registry's, not the query string's: a store only
        // has a tab if it exists, is enabled, and this user holds the
        // channel's orders permission. Both hidden params and the strip's
        // own links carry the FULL key ("venta:2"), so every control inside
        // the panel comes back to this same store rather than to the
        // channel's first one.
        if ($renderingPanel) {
            [$panelClass, $panelStoreId] = $selectedPanel;

            $panel = app($panelClass);

            $data = array_merge(
                $data,
                $panelStoreId === null
                    ? $panel->build($request)
                    : $panel->build($request, $panelStoreId),
                [
                    'selectedChannel' => $selectedTab->id,
                    // The base id is what the view branches on to pick a
                    // wrapper, because "venta:2" and "venta:7" are the same
                    // panel in the same wrapper with a different store.
                    'selectedChannelBase' => explode(':', $selectedTab->id, 2)[0],
                    'selectedChannelLabel' => $selectedTab->label,
                    'panelBaseUrl' => route('integrations.orders'),
                    'panelHiddenParams' => ['channel' => $selectedTab->id],
                ]
            );
        }

        return view('integrations.orders', $data);
    }
}
