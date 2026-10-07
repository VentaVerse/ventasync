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
    private const ORDER_PANEL_SERVICES = [
        'shopee'   => \Extensions\shopee\Services\ShopeeOrdersPanel::class,
        'lazada'   => \Extensions\lazada\Services\LazadaOrdersPanel::class,
        'tiktok'   => \Extensions\tiktok\Services\TikTokOrdersPanel::class,
        'ventacart'    => \Extensions\ventacart\Services\VentaCartOrdersPanel::class,
        'opencart' => \Extensions\opencart\Services\OpenCartOrdersPanel::class,
        'shopify'  => \Extensions\shopify\Services\ShopifyOrdersPanel::class,
        'pedallion' => \Extensions\pedallion\Services\PedallionOrdersPanel::class,
        'woocommerce' => \Extensions\woocommerce\Services\WooOrdersPanel::class,
    ];

    private const ORDER_PANEL_STORE_SCOPED = ['ventacart', 'opencart', 'shopify', 'shopee', 'lazada', 'tiktok', 'woocommerce'];

    private const RETURNS_PANEL_SERVICES = [
        'shopee' => \Extensions\shopee\Services\ShopeeReturnsPanel::class,
        'lazada' => \Extensions\lazada\Services\LazadaReturnsPanel::class,
        'tiktok' => \Extensions\tiktok\Services\TikTokReturnsPanel::class,
    ];

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
                $counts[$card['id']] = null;
            }
        }

        return $counts;
    }

    private function panelFor(string $tabId): ?array
    {
        $base = $tabId;
        $storeId = null;

        if (str_contains($tabId, ':')) {
            [$base, $suffix] = explode(':', $tabId, 2);
            if ($suffix === '' || !ctype_digit($suffix) || (int) $suffix < 1) {
                return null;
            }
            $storeId = (int) $suffix;
        }

        $class = self::ORDER_PANEL_SERVICES[$base] ?? null;

        if ($class === null || ! class_exists($class)) {
            return null;
        }

        $wantsStore = in_array($base, self::ORDER_PANEL_STORE_SCOPED, true);

        if ($wantsStore !== ($storeId !== null)) {
            return null;
        }

        return [$class, $storeId];
    }

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

    private function buildSummary(array $tabs, $user, IntegrationRegistry $registry, bool $withBody): array
    {
        $summary = $this->emptySummary();
        $workspace = app(ChannelWorkspace::class);
        $dayStart = Carbon::now()->startOfDay();

        $inPlace = $this->panelChannelIds($tabs);

        $cards = [];
        foreach ($registry->cardsForAuthorisedRequest($user) as $card) {
            $cards[$card->id] = $card;
        }

        $newest = null;

        foreach ($tabs as $tab) {
            $waiting = $tab->unprocessedCounter === null ? null : $tab->unprocessedCount();
            $stages = $tab->stageCounts();
            $todayOrders = $tab->dailyOrdersCounter === null ? null : $tab->dailyOrdersCount();
            $todayRevenue = $tab->dailyRevenueCounter === null ? null : $tab->dailyRevenue();

            $base = explode(':', $tab->id, 2)[0];
            $card = $cards[$base] ?? null;
            $stateWithReason = $card ? $workspace->stateWithReason($card, $tab->id) : ['state' => null, 'reason' => null];
            $state = $stateWithReason['state'];

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
                'store' => str_contains($tab->label, ': ') ? \Illuminate\Support\Str::after($tab->label, ': ') : null,
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

        $summary['lastOrderAt'] = $summary['feed'] === [] ? $newest : null;

        [$summary['topProducts'], $summary['topProductsMore']] = $withBody ? $this->mergeTopProducts($tabs) : [[], 0];

        return $summary;
    }

    public const TOP_PRODUCTS = 10;

    private const TOP_PRODUCTS_PER_CHANNEL = 100;

    private function mergeTopProducts(array $tabs): array
    {
        $merged = [];

        foreach ($tabs as $tab) {
            foreach ($tab->topProductsToday(self::TOP_PRODUCTS_PER_CHANNEL) as $product) {
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

    private function panelChannelIds(array $tabs): array
    {
        return array_values(array_filter(
            array_map(fn ($tab) => $tab->id, $tabs),
            fn (string $id) => $this->panelFor($id) !== null
        ));
    }

    public function index(Request $request, IntegrationRegistry $registry, ChannelWorkspace $workspace)
    {
        $user = $request->user();
        $cards = $registry->visibleCards($user);

        $now = Carbon::now();
        $staleAfter = $now->copy()->subHours(24);
        $expiringWithin = $now->copy()->addDays(7);

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

        $deriveState = function (?bool $hasToken, ?Carbon $tokenExpiresAt, ?Carbon $lastSyncAt) use ($staleAfter, $expiringWithin) {
            if ($hasToken === false) {
                return ['state' => 'setup', 'reason' => 'no_token'];
            }
            if ($tokenExpiresAt && $tokenExpiresAt->isPast()) {
                return ['state' => 'setup', 'reason' => 'expired'];
            }

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

            if ($card->addStore !== null && $card->stores === []) {
                continue;
            }

            $hasWorkspace = $workspace->hasWorkspace($card);

            if (in_array($baseId, ['shopee', 'lazada', 'tiktok'], true)) {
                $storeRows = $card->stores !== []
                    ? collect($card->stores)->map(fn ($store) => [
                        DB::table($baseId . '_settings')->where('id', (int) substr($store->id, strpos($store->id, ':') + 1))->first(),
                        $store,
                    ])->all()
                    : [[DB::table($baseId . '_settings')->first(), null]];

                foreach ($storeRows as [$row, $store]) {
                    $isSandbox = ($row?->mode ?? 'live') === 'sandbox';
                    $hasToken = $row ? !empty($isSandbox ? $row->sandbox_access_token : $row->access_token) : false;
                    $signInExpiresAt = \App\Support\ChannelWorkspace::signInExpiry($row, $isSandbox);
                    $lastSyncRaw = $row?->last_order_sync_at;
                    $loggingOn = (($row?->api_log_mode ?? 'off') !== 'off');

                    $entries[] = [
                        'key' => $store?->id ?? $baseId,
                        'workspaceKey' => $store?->id ?? $baseId,
                        'label' => $card->name,
                        'store' => $store?->label,
                        'hasToken' => $hasToken,
                        'tokenExpiresAt' => $signInExpiresAt,
                        'lastSyncAt' => $lastSyncRaw ? Carbon::parse($lastSyncRaw) : null,
                        'errors24h' => $loggingOn
                            ? DB::table($baseId . '_api_logs')
                                ->when(
                                    $row !== null && $card->stores !== []
                                        && \Illuminate\Support\Facades\Schema::hasColumn($baseId . '_api_logs', $baseId . '_setting_id'),
                                    fn ($q) => $q->where($baseId . '_setting_id', $row->id)
                                )
                                ->where('created_at', '>=', $staleAfter)->where('ok', 0)->count()
                            : null,
                        'menu' => $store?->menu ?? $card->menu,
                    ];
                }
            } elseif (in_array($baseId, ['ventacart', 'opencart', 'shopify', 'pedallion', 'woocommerce'], true)) {
                $tokenColumn = match ($baseId) {
                    'ventacart' => 'api_token',
                    'opencart' => 'api_key',
                    'shopify' => 'access_token',
                    'pedallion' => 'api_key',
                    'woocommerce' => 'consumer_secret',
                };
                $stores = $card->stores ?: [null];

                foreach ($stores as $store) {
                    $storeId = $store ? (int) substr($store->id, strpos($store->id, ':') + 1) : null;
                    $row = $storeId
                        ? DB::table($baseId . '_settings')->where('id', $storeId)->first()
                        : DB::table($baseId . '_settings')->first();

                    $errors24h = null;
                    if ($row && $baseId === 'ventacart') {
                        $errors24h = ((($row->api_log_mode ?? 'off') !== 'off'))
                            ? DB::table('ventacart_api_logs')->where('ventacart_setting_id', $row->id)->where('created_at', '>=', $staleAfter)->where('ok', 0)->count()
                            : null;
                    } elseif ($row && $baseId === 'shopify') {
                        $errors24h = ((($row->api_log_mode ?? 'off') !== 'off'))
                            ? DB::table('shopify_api_logs')->where('shopify_setting_id', $row->id)->where('created_at', '>=', $staleAfter)->where('ok', 0)->count()
                            : null;
                    } elseif ($row && $baseId === 'opencart') {
                        $errors24h = DB::table('opencart_sync_log')->where('opencart_setting_id', $row->id)->where('created_at', '>=', $staleAfter)->where('status', 'failed')->count();
                    } elseif ($row && $baseId === 'woocommerce') {
                        $errors24h = ((($row->api_log_mode ?? 'off') !== 'off'))
                            ? DB::table('woocommerce_api_logs')->where('woocommerce_setting_id', $row->id)->where('created_at', '>=', $staleAfter)->where('ok', 0)->count()
                            : null;
                    } elseif ($row && $baseId === 'pedallion') {
                        $errors24h = ((($row->api_log_mode ?? 'off') !== 'off'))
                            ? DB::table('pedallion_api_logs')->where('created_at', '>=', $staleAfter)->where('status_code', '>=', 400)->count()
                            : null;
                    }

                    $lastSyncRaw = $row?->last_order_sync_at;

                    $entries[] = [
                        'key' => $store->id ?? $baseId,
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
                    'tokenExpiry' => $entry['hasToken'] === false
                        ? null
                        : $entry['tokenExpiresAt']?->diffForHumans(),
                    'tokenExpired' => $entry['hasToken'] !== false
                        && (bool) $entry['tokenExpiresAt']?->isPast(),
                    'errors24h' => $entry['errors24h'],
                    'enterUrl' => ($hasWorkspace && $entry['workspaceKey'] !== null)
                        ? \App\Support\ChannelWorkspace::overviewUrl($entry['workspaceKey'])
                        : route('channels.module', ['module' => $baseId]),
                    'actionLabel' => $ordersUrl ? 'View orders' : null,
                    'actionUrl' => $ordersUrl,
                    'productsUrl' => $menuUrl($entry['menu'], 'Listings'),
                ];
            }
        }

        $addable = [];
        foreach ($cards as $card) {
            $add = $card->addStore ?? null;
            if (! $add) {
                continue;
            }
            if ($add->permission && (! $user || ! $user->hasPermission($add->permission))) {
                continue;
            }
            $addable[] = [
                'id' => $card->id,
                'name' => $card->name,
                'route' => route($add->route),
                'fields' => $add->fields,
            ];
        }

        return view('channels.board', compact('channels', 'addable'));
    }

    public function module(Request $request, IntegrationRegistry $registry, string $module)
    {
        $card = collect($registry->visibleCards($request->user()))
            ->firstWhere('id', $module);

        if ($card === null) {
            abort(404);
        }

        return view('channels.module', compact('card'));
    }

    public function orders(Request $request, IntegrationRegistry $registry)
    {
        $user = $request->user();
        $tabs = $registry->visibleOrderTabs($user);

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

        $selectedPanel = $selectedTab !== null ? $this->panelFor($selectedTab->id) : null;
        $renderingPanel = $selectedPanel !== null;

        $summary = $this->buildSummary($tabs, $user, $registry, ! $renderingPanel);

        $data = [
            'hasMarketplaces' => true,
            'orderTabs' => $tabs,
            'selectedChannel' => null,
            'selectedChannelBase' => null,
            'selectedChannelLabel' => null,
            'summary' => $summary,
            'panelChannels' => $this->panelChannelIds($tabs),
        ];

        if ($renderingPanel) {
            [$panelClass, $panelStoreId] = $selectedPanel;

            $selectedView = $request->query('view') === 'returns'
                && isset(self::RETURNS_PANEL_SERVICES[$selectedBase = explode(':', $selectedTab->id, 2)[0]])
                    ? 'returns'
                    : 'orders';

            if ($selectedView === 'returns') {
                $panelClass = self::RETURNS_PANEL_SERVICES[$selectedBase];
                $panelStoreId = in_array($selectedBase, self::ORDER_PANEL_STORE_SCOPED, true) ? $panelStoreId : null;
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
                    'fhReturnsCounts' => $selectedView === 'returns'
                        ? $this->returnsCounts($data['summary']['channels'] ?? [])
                        : [],
                    'fhReturnsTotal' => $selectedView === 'returns'
                        ? (($panelData['returns'] ?? $panelData['orders'] ?? null)?->total() ?? 0)
                        : 0,
                    'hasReturnsPanel' => isset(self::RETURNS_PANEL_SERVICES[explode(':', $selectedTab->id, 2)[0]]),
                    'selectedChannel' => $selectedTab->id,
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
