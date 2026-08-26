<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
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
        'shopify'  => \Extensions\shopify\Services\ShopifyOrdersPanel::class,
        'pedallion' => \Extensions\pedallion\Services\PedallionOrdersPanel::class,
    ];

    /**
     * Which of those channels scope their panel to one store, and therefore
     * expect build(Request, int $storeId) and a tab id carrying a store
     * suffix. Declared rather than guessed from the key's shape, so a
     * single-store channel can never be handed a store and a multi-store one
     * can never be rendered without one.
     */
    private const ORDER_PANEL_STORE_SCOPED = ['venta', 'opencart', 'shopify'];

    /**
     * Channels whose RETURNS list can also render in place here, keyed the
     * same way as the orders panels above.
     *
     * Only the two channels that have a returns list at all. Returns used to
     * exist solely as a seller-centre page, so the "Returns and refunds" link
     * inside the orders panel navigated out of the hub and into the channel
     * workspace - a different shell reached from a link that sat among
     * ordinary filters. A channel absent from this map simply does not offer
     * the link here.
     */
    private const RETURNS_PANEL_SERVICES = [
        'shopee' => \Extensions\shopee\Services\ShopeeReturnsPanel::class,
        'lazada' => \Extensions\lazada\Services\LazadaReturnsPanel::class,
        'tiktok' => \Extensions\tiktok\Services\TikTokReturnsPanel::class,
    ];

    /**
     * How many returns each channel holds, for the card strip on the returns
     * view.
     *
     * The strip is the channel switcher, so it stays on the returns view -
     * but its numbers were orders. A heading reading "76 returns" above a
     * highlighted card reading "51" invites the reader to make 51 a part of
     * 76, and it is not: 76 is every Lazada return, 51 is Lazada orders
     * waiting to ship. Both figures are correct and they are answers to
     * different questions, which is precisely why they must not sit adjacent
     * unlabelled.
     *
     * Region-scoped the same way each returns panel scopes its own list, so
     * the card and the list it leads to count the same rows.
     *
     * @var array<string, array{model: class-string, setting: class-string}>
     */
    private const RETURNS_COUNTERS = [
        'shopee' => [
            'model' => \Extensions\shopee\Models\ShopeeReturn::class,
            'setting' => \Extensions\shopee\Models\ShopeeSetting::class,
        ],
        'lazada' => [
            'model' => \Extensions\lazada\Models\LazadaReverseOrder::class,
            'setting' => \Extensions\lazada\Models\LazadaSetting::class,
        ],
    ];

    /**
     * Returns held per channel id, or null for a channel that has no returns
     * list at all - which the card then says in words rather than printing a
     * zero that would read as "none outstanding".
     *
     * @param  array<int, array<string, mixed>>  $channels
     * @return array<string, int|null>
     */
    private function returnsCounts(array $channels): array
    {
        $counts = [];

        foreach ($channels as $card) {
            $base = explode(':', (string) ($card['id'] ?? ''), 2)[0];
            $spec = self::RETURNS_COUNTERS[$base] ?? null;

            if ($spec === null) {
                $counts[$card['id']] = null;
                continue;
            }

            try {
                $region = $spec['setting']::query()->value('region') ?? 'ph';
                $counts[$card['id']] = $spec['model']::query()->where('region', $region)->count();
            } catch (\Throwable $e) {
                // A channel whose table is missing must not take the hub
                // down; it simply has nothing to report.
                $counts[$card['id']] = null;
            }
        }

        return $counts;
    }

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
     * The shape the Summary reads, with nothing in it.
     *
     * Returned when no marketplace is visible and when a channel panel is
     * rendering instead. A single shape means the view never tests whether a
     * key exists, only whether it is empty.
     *
     * @return array<string, mixed>
     */
    private function emptySummary(): array
    {
        return [
            'channels' => [],
            'waiting' => 0,
            'waitingTracked' => 0,
            'stalled' => [],
            'todayOrders' => 0,
            'todayRevenue' => 0.0,
            'todayTracked' => 0,
            'feed' => [],
            'lastOrderAt' => null,
            'topProducts' => [],
            'topProductsMore' => 0,
        ];
    }

    /**
     * Everything the Summary shows.
     *
     * The page holds exactly two kinds of figure and they are not the same
     * kind of fact:
     *
     *   RIGHT NOW - orders waiting on a person, and whether the channel is
     *               still talking to us. A backlog does not reset at midnight.
     *   TODAY     - what has happened since midnight. Resets.
     *
     * Cards carry the first, the body carries the second. The old page mixed
     * both inside one card ("0 to process / 0 today / P0.00") with nothing
     * saying they answered different questions.
     *
     * Nothing here reads the core order table, so this page is not exposed to
     * the two-sources seam recorded on board task #93: every figure comes from
     * the same per-channel counters the cards are built from.
     *
     * $withBody is false while a channel panel is rendering: the cards are
     * still needed, because they are how a reader switches channels, but the
     * feed and the leaderboard are not on screen and cost a query per visible
     * tab each. Building the cards unconditionally is not an optimisation
     * detail - the first cut skipped the whole method behind a panel and the
     * grid rendered empty, taking the channel switcher with it.
     *
     * @param  array<int, \App\Integrations\OrderTab>  $tabs
     * @return array<string, mixed>
     */
    private function buildSummary(array $tabs, $user, IntegrationRegistry $registry, bool $withBody): array
    {
        $summary = $this->emptySummary();
        $workspace = app(ChannelWorkspace::class);
        $dayStart = Carbon::now()->startOfDay();

        // Hoisted out of the loop: it walks every tab, so asking per tab made
        // it quadratic on a seller with a dozen stores.
        $inPlace = $this->panelChannelIds($tabs);

        // Cards resolved once and keyed by id, rather than a registry walk per
        // tab. A multi-store tab ("venta:2") belongs to its channel's card.
        $cards = [];
        foreach ($registry->cardsForAuthorisedRequest($user) as $card) {
            $cards[$card->id] = $card;
        }

        $newest = null;

        foreach ($tabs as $tab) {
            // A null counter means the channel never implemented that figure,
            // which is not the same statement as a measured zero. Printing a
            // zero for it would claim nothing is waiting when the truth is
            // that nobody counted.
            $waiting = $tab->unprocessedCounter === null ? null : $tab->unprocessedCount();
            // The steps behind the waiting figure (to pack, to handover),
            // when the channel tells them apart. See OrderTab::$stages.
            $stages = $tab->stageCounts();
            $todayOrders = $tab->dailyOrdersCounter === null ? null : $tab->dailyOrdersCount();
            $todayRevenue = $tab->dailyRevenueCounter === null ? null : $tab->dailyRevenue();

            // Connection state, from the same method the status board and the
            // channel sidebar read, so the three cannot describe one channel
            // three ways. Null when this user can see the channel's orders but
            // not its card - a real permission shape, and the honest reading
            // is no dot rather than a guess.
            $base = explode(':', $tab->id, 2)[0];
            $card = $cards[$base] ?? null;
            $stateWithReason = $card ? $workspace->stateWithReason($card, $tab->id) : ['state' => null, 'reason' => null];
            $state = $stateWithReason['state'];

            // Stale is a fact ABOUT the count, not a replacement for it.
            //
            // The first cut of this hid the number whenever a channel was
            // stalled, and rendering it showed why that is wrong: Lazada had
            // one order genuinely waiting AND had not synced in three weeks,
            // and the page threw the order away to announce the staleness,
            // then summarised the lot as "Nothing waiting". Both facts are
            // real and the operator needs both - the order still has to be
            // packed. The count is always shown when it is tracked; the stale
            // marker rides alongside it.
            /* "Not syncing" means the sync is not happening: no usable
             * sign-in, or an order sync older than a day. A sign-in that
             * expires within the week is a warning to act on, not a channel
             * that stopped syncing, so it does not count here (live fix
             * 2026-08-26: healthy shops read "not syncing" over it). */
            $stalled = $state === 'setup' || ($state === 'attention' && $stateWithReason['reason'] === 'stale');
            if ($stalled) {
                $summary['stalled'][] = $tab->label;
            }

            if ($waiting !== null) {
                $summary['waiting'] += $waiting;
                $summary['waitingTracked']++;
            }
            if ($todayOrders !== null) {
                $summary['todayOrders'] += $todayOrders;
                $summary['todayTracked']++;
            }
            if ($todayRevenue !== null) {
                $summary['todayRevenue'] += $todayRevenue;
            }

            $summary['channels'][] = [
                'id' => $tab->id,
                'label' => $tab->label,
                'accent' => $tab->accent,
                'url' => in_array($tab->id, $inPlace, true)
                    ? route('channels.fulfilment', array_filter([
                        'channel' => $tab->id,
                        'tab' => $tab->routeParams['tab'] ?? null,
                    ]))
                    : route($tab->routeName, $tab->routeParams),
                'waiting' => $waiting,
                'stages' => $stages,
                'todayOrders' => $todayOrders,
                'todayRevenue' => $todayRevenue,
                'state' => $state,
                'stalled' => $stalled,
            ];

            // Today's orders only. The feed used to be "newest N whenever they
            // happened", which is why a table headed Recent orders showed rows
            // that all read "3 weeks ago". Each channel's newest few are
            // fetched and anything before midnight is dropped; a channel with
            // more than that today contributes its newest, which is what a
            // capped feed should do anyway.
            foreach ($withBody ? $tab->recentOrders(8) : [] as $order) {
                if ($order->orderedAt !== null && ($newest === null || $order->orderedAt > $newest)) {
                    $newest = $order->orderedAt;
                }

                if ($order->orderedAt !== null && $order->orderedAt >= $dayStart) {
                    $summary['feed'][] = ['order' => $order, 'tab' => $tab];
                }
            }
        }

        usort(
            $summary['feed'],
            fn ($a, $b) => ($b['order']->orderedAt?->getTimestamp() ?? 0)
                <=> ($a['order']->orderedAt?->getTimestamp() ?? 0)
        );
        $summary['feed'] = array_slice($summary['feed'], 0, 8);

        // Only worth carrying when there is nothing today: it is what turns an
        // empty feed from an ambiguous blank into "quiet morning" rather than
        // "the sync is broken".
        $summary['lastOrderAt'] = $summary['feed'] === [] ? $newest : null;

        [$summary['topProducts'], $summary['topProductsMore']] = $withBody ? $this->mergeTopProducts($tabs) : [[], 0];

        return $summary;
    }

    /** How many best sellers the hub shows. */
    public const TOP_PRODUCTS = 10;

    /**
     * Each channel's share of the merge. Well past the ten shown: a SKU
     * that is eleventh on one channel and also sold on another would be
     * undercounted, or missed, if each channel cut at ten. At a hundred
     * the tail count under the list is exact for any day this shop has.
     */
    private const TOP_PRODUCTS_PER_CHANNEL = 100;

    /**
     * Today's best sellers, every channel combined and merged by SKU:
     * the ten to show, and how many more SKUs sold today beyond them.
     *
     * Ranked by quantity, the packing question ("what moved"); a tie is
     * broken by revenue, then by name, so every position is decided. Two
     * products on the same count used to have no rule between them and
     * swapped places on every refresh (the owner, 2026-08-26).
     *
     * @param  array<int, \App\Integrations\OrderTab>  $tabs
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    private function mergeTopProducts(array $tabs): array
    {
        $merged = [];

        foreach ($tabs as $tab) {
            foreach ($tab->topProductsToday(self::TOP_PRODUCTS_PER_CHANNEL) as $product) {
                /* One row per VARIATION. The key is the SKU plus the variation's
                 * values as the catalog names them (so Shopee's "Red" and a
                 * storefront's "Color: Red" on the same SKU are one row), and
                 * OpenCart's parent-model lines, which carry the variation only
                 * in their options, split by it. */
                $pairs = \App\Support\CatalogVariations::pairsFor($product->sku, $product->variation);
                $key = $product->sku . '|' . mb_strtolower(implode('/', array_map(fn ($pr) => trim($pr['value']), $pairs)));

                $merged[$key] ??= [
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'variation' => $product->variation,
                    'imageUrl' => $product->imageUrl,
                    'qtySold' => 0,
                    'revenue' => 0.0,
                    'contributors' => [],
                ];
                if (empty($merged[$key]['variation']) && $product->variation) {
                    $merged[$key]['variation'] = $product->variation;
                }

                $merged[$key]['qtySold'] += $product->qtySold;
                $merged[$key]['revenue'] += $product->revenue;

                if ($product->imageUrl && empty($merged[$key]['imageUrl'])) {
                    $merged[$key]['imageUrl'] = $product->imageUrl;
                }

                $merged[$key]['contributors'][] = [
                    'label' => $tab->label,
                    'accent' => $tab->accent,
                    'qty' => $product->qtySold,
                ];
            }
        }

        usort($merged, fn ($a, $b) => [$b['qtySold'], $b['revenue'], $a['name']] <=> [$a['qtySold'], $a['revenue'], $b['name']]);

        return [
            array_slice(array_values($merged), 0, self::TOP_PRODUCTS),
            max(0, count($merged) - self::TOP_PRODUCTS),
        ];
    }

    /**
     * Tab ids whose orders render in place on this page rather than on the
     * channel's own seller centre.
     *
     * @param  array<int, \App\Integrations\OrderTab>  $tabs
     * @return array<int, string>
     */
    private function panelChannelIds(array $tabs): array
    {
        return array_values(array_filter(
            array_map(fn ($tab) => $tab->id, $tabs),
            fn (string $id) => $this->panelFor($id) !== null
        ));
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
        // Returns the state AND why, because the words shown for it depend on
        // the reason: "Needs attention" was covering both an expiring sign-in
        // and an overdue sync, which are different problems with different
        // fixes. ChannelWorkspace::badge() turns the pair into the label, and
        // the channel sidebar uses the same function, so the two screens
        // cannot describe one channel differently again.
        $deriveState = function (?bool $hasToken, ?Carbon $tokenExpiresAt, ?Carbon $lastSyncAt) use ($staleAfter, $expiringWithin) {
            if ($hasToken === false) {
                return ['state' => 'setup', 'reason' => 'no_token'];
            }
            if ($tokenExpiresAt && $tokenExpiresAt->isPast()) {
                return ['state' => 'setup', 'reason' => 'expired'];
            }

            // The expiring sign-in wins when both are true: it is the more
            // urgent, and a stale sync beside a dying token is usually its
            // symptom.
            if ($tokenExpiresAt && $tokenExpiresAt->lessThanOrEqualTo($expiringWithin)) {
                return ['state' => 'attention', 'reason' => 'expiring'];
            }

            if (!$lastSyncAt || $lastSyncAt->lessThan($staleAfter)) {
                return ['state' => 'attention', 'reason' => 'stale'];
            }

            return ['state' => 'connected', 'reason' => null];
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
                // The sign-in's expiry (refresh token), never the access
                // token the cron renews: see ChannelWorkspace::signInExpiry().
                $signInExpiresAt = \App\Support\ChannelWorkspace::signInExpiry($row, $isSandbox);
                $lastSyncRaw = $row?->last_order_sync_at;
                $loggingOn = (bool) ($row?->api_logging ?? false);

                $entries[] = [
                    'key' => $baseId,
                    'workspaceKey' => $baseId,
                    'label' => $card->name,
                    'store' => null,
                    'hasToken' => $hasToken,
                    'tokenExpiresAt' => $signInExpiresAt,
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
                // 'setup_pending' is how a DISABLED store arrives here: the
                // extensions set it from $store->enabled. It was being shown
                // as "Not connected" in the danger colour, which told the
                // seller to set up a store that is configured, has a valid
                // token and has synced orders - it is simply switched off.
                $resolved = (($entry['storeStatus'] ?? null) === 'setup_pending')
                    ? ['state' => 'setup', 'reason' => 'disabled']
                    : $deriveState($entry['hasToken'], $entry['tokenExpiresAt'], $entry['lastSyncAt']);

                $state = $resolved['state'];

                $ordersUrl = $menuUrl($entry['menu'], 'Orders');

                $channels[] = [
                    'key' => $entry['key'],
                    'label' => $entry['label'],
                    'store' => $entry['store'],
                    'state' => $state,
                    'stateReason' => $resolved['reason'],
                    'lastSync' => $entry['lastSyncAt']?->diffForHumans(),
                    // An expiry belongs to a token. Shopee's row holds an
                    // expires_at left behind by an authorisation whose
                    // access_token has since been cleared, which printed
                    // "Setup needed" beside "Token expires 1 week from now"
                    // on the same card - both read from that one row. A
                    // credential that does not exist has no expiry to state.
                    // ChannelWorkspace applies the same rule for the pages
                    // that read their figures through it.
                    'tokenExpiry' => $entry['hasToken'] === false
                        ? null
                        : $entry['tokenExpiresAt']?->diffForHumans(),
                    // Lets the view say "expired" rather than labelling a
                    // date two weeks in the past "Token expires".
                    'tokenExpired' => $entry['hasToken'] !== false
                        && (bool) $entry['tokenExpiresAt']?->isPast(),
                    'errors24h' => $entry['errors24h'],
                    // The channel workspace when this channel has one
                    // (Shopee, Lazada and TikTok, plus every configured
                    // Venta and OpenCart store), otherwise the module page
                    // and never a channel's Settings item. Settings is only one entry in
                    // that channel's full menu (Categories, Product Groups,
                    // Logistics, Reviews, etc. also live there) - preferring
                    // it here meant any channel with a Settings item made
                    // the rest of its own menu unreachable from this board,
                    // since channels.module is otherwise never linked
                    // once the old full-menu card view was replaced by this
                    // one.
                    //
                    // A multi-store channel's rows each open their OWN store
                    // workspace ("venta:2"), because every one of its pages
                    // is store-scoped and a storeless key has nothing to
                    // show.
                    'enterUrl' => ($hasWorkspace && $entry['workspaceKey'] !== null)
                        ? \App\Support\ChannelWorkspace::overviewUrl($entry['workspaceKey'])
                        : route('channels.module', ['module' => $baseId]),
                    'actionLabel' => $ordersUrl ? 'View orders' : null,
                    'actionUrl' => $ordersUrl,
                ];
            }
        }

        return view('channels.board', compact('channels'));
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

        return view('channels.module', compact('card'));
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
            return view('channels.fulfilment', [
                'hasMarketplaces' => false,
                'orderTabs' => [],
                'selectedChannel' => null,
                'selectedChannelLabel' => null,
                'summary' => $this->emptySummary(),
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

        // Everything the Summary shows, assembled in one place. Skipped
        // entirely while a panel is rendering: the Summary is not on screen
        // then, and building it would be several queries per visible tab
        // computed and thrown away on every filter click inside the panel.
        // The cards are built either way: they are the control that switches
        // between channels, so they stay on screen with a panel open. Only the
        // Summary BODY is skipped then - the feed and the leaderboard are the
        // expensive part, one query per visible tab each, and neither is on
        // screen behind a panel.
        $summary = $this->buildSummary($tabs, $user, $registry, ! $renderingPanel);

        $data = [
            'hasMarketplaces' => true,
            'orderTabs' => $tabs,
            'selectedChannel' => null,
            'selectedChannelBase' => null,
            'selectedChannelLabel' => null,
            'summary' => $summary,
            // Every tab id in this list gets an in-place link on the card
            // strip (view keys off membership, not a per-channel literal);
            // every other tab still links out to its own orders page.
            //
            // Computed from the visible tabs rather than from the map's own
            // keys, because a multi-store channel's tab ids carry a store
            // suffix ("venta:2") and the map is keyed by base id. The strip
            // only ever iterates these same tabs, so nothing is lost by
            // narrowing the list to them.
            'panelChannels' => $this->panelChannelIds($tabs),
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

            // ?view=returns swaps the orders list for that channel's returns
            // list on this same page. Only offered where a returns panel
            // exists, and a request for one anywhere else falls back to
            // orders rather than erroring: a stale bookmark should land on a
            // working page, which is the same rule ?channel= already follows.
            $selectedView = $request->query('view') === 'returns'
                && isset(self::RETURNS_PANEL_SERVICES[$selectedBase = explode(':', $selectedTab->id, 2)[0]])
                    ? 'returns'
                    : 'orders';

            if ($selectedView === 'returns') {
                $panelClass = self::RETURNS_PANEL_SERVICES[$selectedBase];
                // No returns list is store-scoped: both channels that have
                // one are single-store.
                $panelStoreId = null;
            }

            $panel = app($panelClass);

            $panelData = $panelStoreId === null
                ? $panel->build($request)
                : $panel->build($request, $panelStoreId);

            $data = array_merge(
                $data,
                $panelData,
                [
                    'selectedView' => $selectedView,
                    // Only built for the returns view; the orders view has no
                    // use for it and it costs two queries per channel.
                    'fhReturnsCounts' => $selectedView === 'returns'
                        ? $this->returnsCounts($data['summary']['channels'] ?? [])
                        : [],
                    // Shopee's returns panel paginates as $returns and
                    // Lazada's as $orders; the head needs one name.
                    'fhReturnsTotal' => $selectedView === 'returns'
                        ? (($panelData['returns'] ?? $panelData['orders'] ?? null)?->total() ?? 0)
                        : 0,
                    // Whether this channel has a returns list to offer at
                    // all, so the panel can show or hide the link rather
                    // than the view guessing per channel.
                    'hasReturnsPanel' => isset(self::RETURNS_PANEL_SERVICES[explode(':', $selectedTab->id, 2)[0]]),
                    'selectedChannel' => $selectedTab->id,
                    // The base id is what the view branches on to pick a
                    // wrapper, because "venta:2" and "venta:7" are the same
                    // panel in the same wrapper with a different store.
                    'selectedChannelBase' => explode(':', $selectedTab->id, 2)[0],
                    'selectedChannelLabel' => $selectedTab->label,
                    'panelBaseUrl' => route('channels.fulfilment'),
                    'panelHiddenParams' => ['channel' => $selectedTab->id],
                ]
            );
        }

        return view('channels.fulfilment', $data);
    }
}
