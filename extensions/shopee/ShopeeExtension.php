<?php

namespace Extensions\shopee;

use App\Extensions\ExtensionProvider;
use App\Integrations\Contracts\DashboardContributor;
use App\Integrations\Contracts\LayoutBannerContributor;
use App\Integrations\Contracts\MarketplaceSourceOptionsProvider;
use App\Integrations\Contracts\MobileMarketplaceProvider;
use App\Integrations\Contracts\OrderFeesContributor;
use App\Integrations\Contracts\RootRouteHandler;
use App\Integrations\Contracts\SkuSyncContributor;
use App\Models\Catalog\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Integrations\Dto\RecentOrder;
use App\Integrations\Dto\TopProduct;
use App\Integrations\IntegrationCard;
use App\Integrations\IntegrationProvider;
use App\Integrations\IntegrationRegistry;
use App\Integrations\MenuItem;
use App\Integrations\OrderTab;
use Extensions\shopee\Models\ShopeeApiLog;
use Extensions\shopee\Models\ShopeeOrder;
use Extensions\shopee\Models\ShopeeProductLink;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\Services\Shopee\ShopeeClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

use App\Integrations\Contracts\CredentialRevealer;
use App\Integrations\Contracts\ListingStateSource;
use App\Integrations\Concerns\RevealsCredentials;

class ShopeeExtension extends ExtensionProvider implements ListingStateSource, IntegrationProvider, SkuSyncContributor, DashboardContributor, OrderFeesContributor, \App\Integrations\Contracts\MarketplaceFeeSource, MobileMarketplaceProvider, LayoutBannerContributor, MarketplaceSourceOptionsProvider, RootRouteHandler, CredentialRevealer, \App\Integrations\Contracts\WebhookReceiver, \App\Integrations\Contracts\StoreOptionsProvider, \App\Integrations\Contracts\ProductRemover, \App\Integrations\Contracts\VariationForgetter, \App\Integrations\Contracts\MarketplaceOrderRefRenderer, \App\Integrations\Contracts\SaleLinesSource
{
    private const MOBILE_TAB_STATUS_MAP = [
        'UNPAID'              => ['UNPAID'],
        'PENDING'             => ['READY_TO_SHIP', 'PROCESSED'],
        'SHIPPING'            => ['SHIPPED'],
        'DELIVERED_COMPLETED' => ['COMPLETED'],
        'CANCELLED'           => ['CANCELLED', 'IN_CANCEL'],
        'FAILED_DELIVERY'     => ['RETRY_SHIP'],
        'RETURN'              => ['AWAITING_RETURN', 'IN_RETURN', 'RETURNED'],
    ];

    private const MOBILE_PENDING_SUB_MAP = [
        'to_pack'     => ['READY_TO_SHIP'],
        'to_handover' => ['PROCESSED'],
    ];

    protected string $id = 'shopee';

    public function boot(): void
    {
        parent::boot();

        // Multi-store S4 (#194): let every route() call fill {store} even
        // outside a Shopee page (hub, fulfilment tabs, test code).
        \Extensions\shopee\Models\ShopeeSetting::syncUrlDefault();

        /* ONE LINK CHECK PER REQUEST (the owner, 2026-09-09). Its shop scan is
         * memoised on the instance, and Shopee publishes no get-item-by-SKU,
         * so the scan is about forty calls. A push of twenty ticked products
         * resolving twenty instances would page the whole shop twenty times;
         * one instance pages it once and every product after the first reads
         * the memo. The batch check has always shared an instance this way -
         * this is what makes the bulk push able to afford the guard too. */
        $this->app->scoped(\Extensions\shopee\Services\Shopee\ShopeeLinkCheck::class);

        // Registered in every runtime, not only the console: the Automations
        // tab's Run now calls Artisan inside a web request (live, 2026-09-07).
        $this->commands([
            \Extensions\shopee\Commands\ShopeePushStock::class,
            \Extensions\shopee\Commands\ShopeePushPrice::class,
            \Extensions\shopee\Commands\ShopeeFindDuplicates::class,
            \Extensions\shopee\Commands\ShopeeRefreshListingStatus::class,
            // TEMPORARY, delete with the command it names.
            \Extensions\shopee\Commands\ShopeeWhyUnsynced::class,
            \Extensions\shopee\Commands\ShopeeRefreshToken::class,
            \Extensions\shopee\Commands\ShopeeSyncOrders::class,
            \Extensions\shopee\Commands\ShopeeSyncReviews::class,
        ]);

        $this->app->make(IntegrationRegistry::class)->register($this);
    }

    /** A deleted catalog variation is forgotten here too (VariationForgetter, 2026-09-07). */
    public function forgetVariations(int $productId, array $skus): void
    {
        \Extensions\shopee\Services\Shopee\ShopeeVariationForgetter::forget($productId, $skus);
    }

    public function integrationId(): string
    {
        return $this->id;
    }

    public function integrationCards(): array
    {
        /* Multi-store S4 (#194): the converted shape Venta, OpenCart and
         * Shopify wear. One card for the channel; one StoreLink per
         * settings row with the store's own menu, every item carrying its
         * {store}. The card-level menu keeps only Settings, which is the
         * channel-global page (it is also Shopee's OAuth landing, pinned
         * storeless by the Open Platform console). A store still being
         * configured stays visible as setup_pending. */
        $stores = [];
        foreach ($this->allStores() as $store) {
            $p = ['store' => (int) $store->id];
            $stores[] = new \App\Integrations\StoreLink(
                id: $this->id . ':' . $store->id,
                label: $store->store_name ?: ('Store #' . $store->id),
                menu: [
                    new MenuItem('Orders', 'ext.shopee.orders.index', 'view_shopee/order', null, $p, 'Sell'),
                    new MenuItem('Returns', 'ext.shopee.orders.returns', 'view_shopee/order', null, $p, 'Sell'),
                    new MenuItem('Vouchers', 'ext.shopee.vouchers.index', 'view_shopee/voucher', null, $p, 'Sell'),
                    new MenuItem('Listings', 'ext.shopee.products.index', 'view_shopee/product', null, $p, 'Catalog'),
                    new MenuItem('Product Groups', 'ext.shopee.product-groups.index', 'view_shopee/product_group', null, $p, 'Catalog'),
                    new MenuItem('Description Templates', 'ext.shopee.description-templates.index', 'view_shopee/description_template', null, $p, 'Catalog'),
                    new MenuItem('Watermarks', 'ext.shopee.watermarks.index', 'view_shopee/watermark_template', null, $p, 'Catalog'),
                    new MenuItem('Categories', 'ext.shopee.categories.index', 'view_shopee/category', null, $p, 'Catalog'),
                    // Catalog, not Channel (the owner, 2026-09-03): the enabled
                    // couriers ride every listing payload's logistic_info,
                    // so this is catalogue material even though it feels
                    // settings-adjacent. Also leaves Settings alone in the
                    // Channel group, so the menubar renders it as the same
                    // Settings menu every other channel shows.
                    new MenuItem('Logistics', 'ext.shopee.logistics.index', 'view_shopee/logistics', null, $p, 'Catalog'),
                    new MenuItem('Import', 'ext.shopee.products.import', 'view_shopee/product', null, $p, 'Catalog'),
                    new MenuItem('Settings', 'ext.shopee.settings.show', 'view_shopee/settings', null, $p, 'Channel', children: [
                        ['label' => 'Connection', 'section' => 'connection'],
                        ['label' => 'Status mapping', 'section' => 'status'],
                        ['label' => 'API explorer', 'section' => 'explorer', 'permission' => 'manage_shopee/settings'],
                        ['label' => 'API log', 'section' => 'logs'],
                        ['label' => 'Automations', 'section' => 'automations', 'permission' => 'manage_shopee/settings'],
                    ]),
                ],
                status: $store->enabled ? 'active' : 'setup_pending',
            );
        }

        return [
            new IntegrationCard(
                id: $this->id,
                name: 'Shopee',
                tagline: 'Shopee marketplace integration. OAuth, products, orders, returns.',
                icon: 'shopee',
                accent: '#ee4d2d',
                // Card + menu use the VIEW tier so read-only operators still
                // see Shopee in the Integrations hub and contextual sidebar.
                // User::hasPermission('view_shopee/dashboard') is satisfied by the
                // view or the manage tier (manage implies view), so managers
                // are not excluded by tightening this gate.
                // The dashboard's own derived key (shopee/dashboard), not the
                // legacy view_shopee: the card decides who sees this channel
                // in the hub and the nav, and that must be the same answer
                // the dashboard's door gives, or the menu offers a 404.
                permission: 'view_shopee/dashboard',
                menu: [
                    new MenuItem('Settings', 'ext.shopee.index', 'view_shopee/settings', group: 'Channel'),
                ],
                stores: $stores,
                addStore: new \App\Integrations\AddStoreAction(
                    route: 'ext.shopee.stores.store',
                    permission: 'manage_shopee/settings',
                    label: 'Add store',
                ),
            ),
        ];
    }

    /**
     * Every settings row, enabled or not - the Integrations UI keeps a
     * freshly-created, still-disabled store visible so the operator can
     * finish configuring it (Venta's allStores, verbatim in spirit).
     *
     * @return iterable<\Extensions\shopee\Models\ShopeeSetting>
     */
    protected function allStores(): iterable
    {
        try {
            if (! Schema::hasTable('shopee_settings')) {
                return [];
            }

            return ShopeeSetting::query()->orderBy('id')->get();
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function orderTabs(): array
    {
        /* Multi-store S4 (#194): one tab per enabled store, counters
         * scoped to the store's own rows. With a single store the tab
         * keeps the channel's name - renaming the owner's hub tab to "Main
         * store" would say less, not more; the store names take over the
         * moment a second store exists and the name has to distinguish. */
        if (! Schema::hasTable('shopee_settings')) {
            return [];
        }
        $stores = ShopeeSetting::query()->where('enabled', true)->orderBy('id')->get(['id', 'store_name']);

        return $stores->map(fn ($store) => $this->buildOrderTab(
            (int) $store->id,
            \App\Support\StoreLabel::of('Shopee', $store->store_name ?: ('Store #' . $store->id))
        ))->all();
    }

    protected function buildOrderTab(int $storeId, string $label): OrderTab
    {
        $shopeeStatusCount = function (array $statuses) use ($storeId): int {
            if (!Schema::hasTable('shopee_orders')) {
                return 0;
            }

            return ShopeeOrder::query()->where('shopee_setting_id', $storeId)->whereIn('status', $statuses)->count();
        };

        return
            new OrderTab(
                id: $this->id . ':' . $storeId,
                label: $label,
                icon: 'shopee',
                accent: '#ee4d2d',
                routeName: 'ext.shopee.orders.index',
                routeParams: ['store' => $storeId],
                // OrderTab visibility uses view tier so a Shopee Orders Viewer
                // still sees the marketplace's orders tab on the unified Orders
                // workspace. The destination route GETs are read-tier gated,
                // and per-action write buttons are gated separately in the view.
                permission: 'view_shopee/order',
                unprocessedCounter: function () use ($storeId) {
                    if (!Schema::hasTable('shopee_orders')) {
                        return 0;
                    }
                    return ShopeeOrder::query()
                        ->where('shopee_setting_id', $storeId)
                    /* THE SAME STATUSES THE "To Ship" TAB GROUPS.
                     *
                     * This counted only the first sub-step, so the head
                     * sentence and the tab strip 40px below it disagreed on
                     * every channel: "Nothing waiting" over "To Ship 1",
                     * because the one order sat at the second step. An order
                     * awaiting collection is still an order the seller is
                     * holding - it is waiting on them, which is the only
                     * question this figure exists to answer.
                     *
                     * Kept identical to the tab map in the orders panel. If
                     * one moves the other has to, and FoundationTest asserts
                     * they still agree. */
                        ->whereIn('status', ['READY_TO_SHIP', 'PROCESSED'])
                        ->count();
                },
                /* The steps while the seller holds the parcel, each counted alone:
                 * the fulfilment card leads with To Pack and prints To Handover beside
                 * it, which the staff count against the shelf. The statuses are the
                 * orders panel's own sub-tab map, so the card and the tab agree. */
                stages: [
                    ['key' => 'to_pack', 'label' => 'to pack', 'counter' => fn () => $shopeeStatusCount(['READY_TO_SHIP'])],
                    ['key' => 'to_handover', 'label' => 'to handover', 'counter' => fn () => $shopeeStatusCount(['PROCESSED'])],
                ],
                dailyOrdersCounter: function () use ($storeId) {
                    if (!Schema::hasTable('shopee_orders')) {
                        return 0;
                    }
                    return ShopeeOrder::query()
                        ->where('shopee_setting_id', $storeId)
                        ->where('order_created_at', '>=', now()->startOfDay())
                        ->count();
                },
                dailyRevenueCounter: function () use ($storeId) {
                    if (!Schema::hasTable('shopee_orders') || !Schema::hasTable('shopee_order_products')) {
                        return 0.0;
                    }
                    return (float) DB::table('shopee_order_products as p')
                        ->join('shopee_orders as o', 'o.id', '=', 'p.shopee_order_id')
                        ->where('o.shopee_setting_id', $storeId)
                        ->where('o.order_created_at', '>=', now()->startOfDay())
                        ->sum(DB::raw('p.quantity * p.price'));
                },
                topProductsCallback: function (int $limit) use ($storeId) {
                    if (!Schema::hasTable('shopee_orders') || !Schema::hasTable('shopee_order_products')) {
                        return [];
                    }
                    return DB::table('shopee_order_products as p')
                        ->join('shopee_orders as o', 'o.id', '=', 'p.shopee_order_id')
                        ->where('o.shopee_setting_id', $storeId)
                        ->where('o.order_created_at', '>=', now()->startOfDay())
                        ->whereNotNull('p.sku')
                        ->where('p.sku', '!=', '')
                        ->groupBy('p.sku')
                        ->orderByDesc('qty_sold')->orderByDesc('revenue')->orderBy('name')
                        ->limit($limit)
                        ->select(
                            'p.sku',
                            DB::raw('MAX(p.name) as name'),
                            DB::raw('MAX(p.image) as image'),
                            DB::raw('SUM(p.quantity) as qty_sold'),
                            DB::raw('MAX(p.variation) as variation'),
                            DB::raw('SUM(p.quantity * p.price) as revenue'),
                        )
                        ->get()
                        ->map(fn ($r) => new TopProduct(
                            sku: (string) $r->sku,
                            name: (string) ($r->name ?? $r->sku),
                            imageUrl: $r->image ?: null,
                            qtySold: (int) $r->qty_sold,
                            revenue: (float) $r->revenue,
                            variation: trim((string) ($r->variation ?? '')) ?: null,
                        ))->all();
                },
                recentOrdersCallback: function (int $limit) use ($storeId) {
                    if (!Schema::hasTable('shopee_orders')) {
                        return [];
                    }
                    // `raw` comes along because the buyer's name lives in it
                    // and nowhere else on this table. Without it every Shopee
                    // row on the fulfilment feed printed the "Guest" fallback,
                    // which is not true of a marketplace order: there is a
                    // buyer, the name was simply never read.
                    $orders = ShopeeOrder::query()
                        ->where('shopee_setting_id', $storeId)
                        ->orderByDesc('order_created_at')
                        ->limit($limit)
                        ->get(['id', 'order_sn', 'status', 'order_created_at', 'raw']);

                    $totals = DB::table('shopee_order_products')
                        ->whereIn('shopee_order_id', $orders->pluck('id'))
                        ->select('shopee_order_id', DB::raw('SUM(quantity * price) as total'))
                        ->groupBy('shopee_order_id')
                        ->pluck('total', 'shopee_order_id');

                    return $orders->map(fn ($o) => new RecentOrder(
                        reference: (string) $o->order_sn,
                        customerName: (function () use ($o) {
                            $raw = is_array($o->raw) ? $o->raw : [];
                            // Shopee has shipped both spellings of this field
                            // over the versions of the order API this
                            // extension talks to; the orders table's own
                            // buyer filter searches both for the same reason.
                            foreach (['buyer_username', 'buyer_user_name'] as $key) {
                                $name = trim((string) ($raw[$key] ?? ''));
                                if ($name !== '') {
                                    return $name;
                                }
                            }

                            return null;
                        })(),
                        total: (float) ($totals[$o->id] ?? 0),
                        statusLabel: (string) $o->status,
                        orderedAt: $o->order_created_at,
                        url: route('ext.shopee.orders.show', $o->order_sn),
                    ))->all();
                },
            );
    }

    /**
     * React to a SKU change on a catalog product by pushing the new
     * item_sku to every linked Shopee item via the Shopee API.
     */
    public function pushSkuChanges(int $productId, array $skuChanges): ?string
    {
        $links = ShopeeProductLink::where('product_id', $productId)->get();
        if ($links->isEmpty()) {
            return null;
        }

        $results = [];

        if (!empty($skuChanges['product_sku'])) {
            $newSku = $skuChanges['product_sku']['new'];
            $itemLinks = $links->whereNull('shopee_model_id')->unique('shopee_item_id');

            foreach ($itemLinks as $link) {
                $itemId = (int) $link->shopee_item_id;
                if ($itemId <= 0) {
                    continue;
                }

                $apiResult = $this->shopeeUpdateItemSku($link, $itemId, $newSku);
                if ($apiResult) {
                    $results[] = $apiResult;
                }
            }
        }

        if (!empty($skuChanges['option_skus'])) {
            $results[] = 'option SKU(s) updated locally';
        }

        return empty($results) ? null : 'Shopee: ' . implode('; ', $results);
    }

    private function shopeeUpdateItemSku(ShopeeProductLink $link, int $itemId, string $newSku): ?string
    {
        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            return null;
        }

        try {
            $client = new ShopeeClient();
            $path = '/api/v2/product/update_item';
            $body = [
                'item_id'  => $itemId,
                'item_sku' => $newSku,
            ];

            $result = $client->shopPost(
                $auth['mode'],
                (int) $auth['partner_id'],
                (string) $auth['partner_key'],
                (string) $auth['access_token'],
                (int) $auth['shop_id'],
                $path,
                [],
                $body
            );

            ShopeeApiLog::safeCreate([
                'pack'            => 'shopee.product.update_item.sku_sync',
                'method'          => 'POST',
                'api_path'        => $path,
                'auth_required'   => true,
                'request_params'  => $body,
                'response_status' => (int) ($result['status'] ?? 0),
                'ok'              => (bool) ($result['ok'] ?? false),
                'response_body'   => $result['body'] ?? null,
                'user_id'         => auth()->id(),
            ]);

            $isOk = ($result['ok'] ?? false) && (($result['body']['error'] ?? '') === '' || ($result['body']['error'] ?? null) === null);

            $link->update([
                'last_synced_at'          => now(),
                'last_sync_action'        => 'sku_sync',
                'last_sync_ok'            => $isOk,
                'last_sync_error_code'    => $isOk ? null : (string) ($result['body']['error'] ?? ''),
                'last_sync_error_message' => $isOk ? null : (string) ($result['body']['message'] ?? ''),
            ]);
            if ($isOk) {
                app(\Extensions\shopee\Services\Shopee\ShopeeListingStates::class)
                    ->forStore((int) $link->shopee_setting_id)->recordOutcome((int) $link->product_id, null);
            }

            return "Item {$itemId}: " . ($isOk ? 'OK' : 'Failed');
        } catch (\Throwable $e) {
            Log::warning('Shopee item_sku update failed', [
                'item_id' => $itemId,
                'error' => $e->getMessage(),
            ]);
            return "Item {$itemId}: Error";
        }
    }

    /**
     * Shopee widgets for the core dashboard: pending order count and
     * sync-status row pulled from the settings table.
     */
    public function dashboardData(): array
    {
        $data = [
            'shopeePending' => 0,
            'shopeeSyncStatus' => null,
        ];

        if (Schema::hasTable('shopee_orders')) {
            $data['shopeePending'] = (int) DB::table('shopee_orders')
                // To Ship = to pack + to handover, the same set unprocessedCount() sums.
                ->whereIn('status', ['READY_TO_SHIP', 'PROCESSED'])
                ->count();
        }

        if (Schema::hasTable('shopee_settings')) {
            $spSetting = ShopeeSetting::defaultStore();
            if ($spSetting) {
                /* The listing troubles ride the chip (Phase B, #184): a
                 * violation or a vanished listing used to live only on a
                 * page nobody opens daily. Counted by aggregate, and a
                 * failure here must not take the dashboard down. */
                $flags = null;
                $attention = 0;
                try {
                    $sum = app(\Extensions\shopee\Services\Shopee\ShopeeListingStates::class)->summary();
                    $attention = (int) $sum['attention'];
                    /* Each figure carries where it goes (#240): the Listings page
                       filtered by the same query that counted it. The words are
                       written once, in App\Integrations\Listings\ListingTrouble,
                       so the chip and the page it lands on say the same thing. */
                    $flags = \App\Integrations\Listings\ListingTrouble::figures($sum, fn (string $bucket) => route('ext.shopee.products.index', ['store' => $spSetting->id, 'state' => $bucket]));
                } catch (\Throwable) {
                    // the chip simply carries no flags line
                }

                $data['shopeeSyncStatus'] = (object) [
                    'order_sync_at' => $spSetting->last_order_sync_at,
                    'push_stock_at' => $spSetting->last_stock_push_at,
                    'return_sync_at' => $spSetting->last_return_sync_at,
                    'expires_at'    => $spSetting->expires_at,
                    'listing_flags' => $flags,
                    'listing_attention' => $attention,
                ];
            }
        }


        // Per-store figures ride alongside the channel-level ones.
        $data += $this->perStoreDashboardFigures();

        return $data;
    }

    /**
     * Per-store dashboard figures (#194, 2026-09-07): with two stores of one
     * channel the strip used to show one blended row on both, because the
     * contribution was keyed by channel. Each store now reports its own
     * waiting count and its own freshness under its tab id ("shopee:2"),
     * which the dashboard prefers over the channel-level key.
     *
     * @return array{channelPending: array<string, int>, channelSyncStatuses: array<string, object>}
     */
    private function perStoreDashboardFigures(): array
    {
        $pending = [];
        $statuses = [];
        if (! Schema::hasTable('shopee_settings')) {
            return ['channelPending' => $pending, 'channelSyncStatuses' => $statuses];
        }

        foreach (ShopeeSetting::query()->orderBy('id')->get() as $store) {
            $key = \App\Support\StoreKey::of('shopee', (int) $store->id);
            if (Schema::hasTable('shopee_orders')) {
                $pending[$key] = (int) DB::table('shopee_orders')
                    ->where('shopee_setting_id', $store->id)
                    // To Ship = to pack + to handover, matching unprocessedCount().
                    ->whereIn('status', ['READY_TO_SHIP', 'PROCESSED'])
                    ->count();
            }
            /* The listing engine scopes to the bound store, so each store's
             * flags are its own; a failure must not take the strip down. */
            $flags = null;
            $attention = 0;
            $previous = app()->bound('shopee.route-store') ? app('shopee.route-store') : null;
            try {
                app()->instance('shopee.route-store', $store);
                $sum = app(\Extensions\shopee\Services\Shopee\ShopeeListingStates::class)->summary();
                $attention = (int) $sum['attention'];
                /* Each figure carries where it goes (#240): the Listings page
                   filtered by the same query that counted it. The words are
                   written once, in App\Integrations\Listings\ListingTrouble,
                   so the chip and the page it lands on say the same thing. */
                $flags = \App\Integrations\Listings\ListingTrouble::figures($sum, fn (string $bucket) => route('ext.shopee.products.index', ['store' => $store->id, 'state' => $bucket]));
            } catch (\Throwable) {
                // this store's chip simply carries no flags line
            } finally {
                if ($previous !== null) {
                    app()->instance('shopee.route-store', $previous);
                } else {
                    app()->forgetInstance('shopee.route-store');
                }
            }

            $statuses[$key] = (object) [
                'order_sync_at' => $store->last_order_sync_at,
                'push_stock_at' => $store->last_stock_push_at,
                'return_sync_at' => $store->last_return_sync_at ?? null,
                'expires_at' => $store->expires_at,
                'listing_flags' => $flags,
                'listing_attention' => $attention,
            ];
        }

        return ['channelPending' => $pending, 'channelSyncStatuses' => $statuses];
    }

    /**
     * Shopee fees live in shopee_orders.fees['order_income'] from the
     * Shopee escrow detail API. Returns commission / service / transaction
     * fee / withholding tax as a structured breakdown.
     */
    /**
     * What Shopee charged for this order, in core's four buckets.
     *
     * The itemisation matches what the Fee Analysis report has always
     * shown, so normalizing changes no figure: commission on its own, the
     * service and seller transaction fees together as "other", and
     * withholding tax as the payment-side deduction.
     *
     * DELIVERY IS WHAT THE SELLER BORE, not what the courier charged.
     * Shopee's payload carries both plus the rebate, and over 1,691 real
     * orders the courier charged 99,261 while the seller bore 79. Counting
     * the courier's price would understate net profit by about 99,340 on
     * that sample alone.
     */
    public function saleLinesForOrder(int $coreOrderId, float $itemsSubtotal, float $shipping): ?array
    {
        $order = \Extensions\shopee\Models\ShopeeOrder::query()->withoutGlobalScope('shopeeStore')->where('catalog_order_id', $coreOrderId)->first();
        if (! $order) {
            return null;
        }
        $fees = is_array($order->fees) ? $order->fees : [];
        $income = $fees['order_income'] ?? $fees;

        return \Extensions\shopee\Services\ShopeeSaleLines::lines(is_array($income) ? $income : [], $itemsSubtotal, $shipping);
    }

    public function feeBucketsForOrder(int $coreOrderId): ?array
    {
        if (! Schema::hasTable('shopee_orders')) {
            return null;
        }

        $row = ShopeeOrder::query()->withoutGlobalScope('shopeeStore')
            ->where('catalog_order_id', $coreOrderId)->first();
        if (! $row) {
            return null;
        }

        $payload = \App\Integrations\Orders\FeePayload::decode($row->fees);
        $income = is_array($payload['order_income'] ?? null) ? $payload['order_income'] : $payload;
        if (\App\Integrations\Orders\FeePayload::isEmpty($income)) {
            return null;
        }

        $amount = fn (string $k) => \App\Integrations\Orders\FeePayload::amount($income, $k);

        $borne = $amount('actual_shipping_fee')
            - $amount('shopee_shipping_rebate')
            - $amount('buyer_paid_shipping_fee')
            - $amount('shipping_fee_discount_from_3pl')
            - $amount('seller_shipping_discount');

        $other = $amount('service_fee') + $amount('seller_transaction_fee');
        $unitemised = \Extensions\shopee\Services\ShopeeOrderBreakdown::from($income)?->unitemised() ?? 0.0;
        if ($unitemised < 0) {
            $other += abs($unitemised);
        }

        return [
            'commission' => $amount('commission_fee'),
            'payment' => $amount('withholding_tax'),
            'shipping' => max(0.0, round($borne, 2)),
            'other' => round($other, 2),
            'voucher' => $amount('voucher_from_seller') + $amount('seller_coin_cash_back'),
        ];
    }

    public function renderOrderRef(string $marketplaceSource, string $marketplaceOrderId): ?array
    {
        if ($marketplaceSource !== 'shopee') {
            return null;
        }

        $store = ShopeeOrder::query()->withoutGlobalScope('shopeeStore')->where('order_sn', $marketplaceOrderId)->value('shopee_setting_id');

        return ['display' => $marketplaceOrderId, 'url' => $store ? route('ext.shopee.orders.show', ['store' => $store, 'orderSn' => $marketplaceOrderId]) : null];
    }

    public function feesForOrder(Order $order): ?array
    {
        if ((string) $order->marketplace_source !== 'shopee') {
            return null;
        }

        $mktOrderId = (string) $order->marketplace_order_id;
        if ($mktOrderId === '' || !Schema::hasTable('shopee_orders')) {
            return null;
        }

        $spOrder = ShopeeOrder::where('order_sn', $mktOrderId)->first();
        if (!$spOrder || empty($spOrder->fees)) {
            return ['total' => 0, 'items' => [], 'source' => 'shopee'];
        }

        $feesRaw = is_array($spOrder->fees) ? $spOrder->fees : [];
        $income = $feesRaw['order_income'] ?? $feesRaw;
        if (!is_array($income)) {
            return ['total' => 0, 'items' => [], 'source' => 'shopee'];
        }

        $items = [];
        $total = 0;

        $commission     = abs((float) ($income['commission_fee'] ?? 0));
        $serviceFee     = abs((float) ($income['service_fee'] ?? 0));
        $txnFee         = abs((float) ($income['seller_transaction_fee'] ?? 0));
        $withholdingTax = abs((float) ($income['withholding_tax'] ?? 0));

        if ($commission > 0)     { $items[] = ['label' => 'Commission',       'amount' => $commission];     $total += $commission; }
        if ($serviceFee > 0)     { $items[] = ['label' => 'Service Fee',      'amount' => $serviceFee];     $total += $serviceFee; }
        if ($txnFee > 0)         { $items[] = ['label' => 'Transaction Fee', 'amount' => $txnFee];          $total += $txnFee; }
        if ($withholdingTax > 0) { $items[] = ['label' => 'Withholding Tax', 'amount' => $withholdingTax]; $total += $withholdingTax; }

        return ['total' => round($total, 2), 'items' => $items, 'source' => 'shopee'];
    }

    // ── Mobile API ─────────────────────────────────────────────

    public function mobilePlatformSlug(): string
    {
        return 'shopee';
    }

    public function mobileIndexResponse(Request $request): array
    {
        $tab = strtoupper((string) $request->query('tab', 'ALL'));
        $search = trim((string) $request->query('q', ''));
        $pendingSub = (string) $request->query('pending_sub', '');
        if ($tab !== 'PENDING' || !array_key_exists($pendingSub, self::MOBILE_PENDING_SUB_MAP)) {
            $pendingSub = '';
        }

        $region = ShopeeSetting::defaultStore()->region ?? 'ph';
        $base = ShopeeOrder::query()->where('region', $region);
        $query = ShopeeOrder::query()->where('region', $region);

        if ($tab !== 'ALL') {
            $statuses = $tab === 'PENDING' && $pendingSub !== ''
                ? self::MOBILE_PENDING_SUB_MAP[$pendingSub]
                : (self::MOBILE_TAB_STATUS_MAP[$tab] ?? []);
            if (!empty($statuses)) {
                $query->whereIn('status', $statuses);
            }
        }

        if ($search !== '') {
            $like = '%' . $search . '%';
            $query->where(function ($q) use ($like) {
                $q->where('order_sn', 'like', $like)
                    ->orWhere('raw->buyer_username', 'like', $like)
                    ->orWhere('raw->buyer_user_name', 'like', $like);
            });
        }

        $tabCounts = ['ALL' => (clone $base)->count()];
        foreach (self::MOBILE_TAB_STATUS_MAP as $k => $statuses) {
            $tabCounts[$k] = !empty($statuses) ? (clone $base)->whereIn('status', $statuses)->count() : 0;
        }
        $pendingSubCounts = [];
        foreach (self::MOBILE_PENDING_SUB_MAP as $subKey => $subStatuses) {
            $pendingSubCounts[$subKey] = !empty($subStatuses) ? (clone $base)->whereIn('status', $subStatuses)->count() : 0;
        }

        $orders = $query->orderByDesc('order_created_at')->orderByDesc('created_at')->paginate(20);

        $data = $orders->map(function ($order) {
            $raw = $order->raw ?? [];
            $firstProduct = $order->products()->orderBy('id')->first();
            $productRaw = $firstProduct?->raw ?? [];
            $productImage = $firstProduct?->image
                ?? ($productRaw['image_info']['image_url'] ?? null)
                ?? ($productRaw['product_main_image'] ?? null);

            return [
                'id'              => $order->id,
                'order_id'        => (string) $order->order_sn,
                'status'          => $order->status,
                'buyer_name'      => $raw['buyer_username'] ?? $raw['buyer_user_name'] ?? '',
                'total_amount'    => isset($raw['total_amount']) ? (float) $raw['total_amount'] : null,
                'currency'        => $raw['currency'] ?? \App\Support\Money::defaultSymbol(),
                'items_count'     => $order->products()->count(),
                'product_image'   => $productImage ?: null,
                'payment_method'  => $raw['payment_method'] ?? null,
                'tracking_number' => $raw['tracking_number'] ?? null,
                'date'            => $order->order_created_at?->format('Y-m-d H:i'),
            ];
        });

        return [
            'data'               => $data,
            'current_page'       => $orders->currentPage(),
            'last_page'          => $orders->lastPage(),
            'total'              => $orders->total(),
            'tab_counts'         => $tabCounts,
            'pending_sub_counts' => $pendingSubCounts,
        ];
    }

    public function mobileShowResponse(int $id): ?array
    {
        $order = ShopeeOrder::with('products')->find($id);
        if (!$order) {
            return null;
        }

        $raw = $order->raw ?? [];
        $addr = $raw['recipient_address'] ?? $raw['shipping_address'] ?? [];

        $products = $order->products->map(function ($p) {
            $pRaw = $p->raw ?? [];
            return [
                'name'      => $p->name,
                'sku'       => $p->sku ?? '',
                'variation' => $p->variation ?? '',
                'quantity'  => $p->quantity,
                'price'     => $p->price ?? 0,
                'image'     => $p->image ?? ($pRaw['image_info']['image_url'] ?? ''),
            ];
        });

        return [
            'order_id'          => $order->order_sn,
            'status'            => $order->status,
            'buyer_name'        => $raw['buyer_username'] ?? $raw['buyer_user_name'] ?? '',
            'total_amount'      => isset($raw['total_amount']) ? (float) $raw['total_amount'] : null,
            'currency'          => $raw['currency'] ?? \App\Support\Money::defaultSymbol(),
            'payment_method'    => $raw['payment_method'] ?? '',
            'shipping_provider' => $raw['shipping_carrier'] ?? $raw['checkout_shipping_carrier'] ?? '',
            'tracking_number'   => $raw['tracking_no'] ?? $raw['tracking_number'] ?? '',
            'shipping_name'     => $addr['name'] ?? '',
            'shipping_phone'    => $addr['phone'] ?? '',
            'shipping_address'  => $addr['full_address']
                ?? trim(implode(', ', array_filter([
                    $addr['address'] ?? '',
                    $addr['city'] ?? '',
                    $addr['state'] ?? '',
                    $addr['zipcode'] ?? '',
                ]))),
            'date'              => $order->order_created_at?->format('Y-m-d H:i'),
            'products'          => $products->values(),
        ];
    }

    // ── Layout banner + source label ───────────────────────────

    public function layoutBanners(): array
    {
        $banners = [];

        /* Credential state is NOT answered here any more. It read
           settings.expires_at directly - the LIVE column - while these
           installs select their credential by `mode`, so a sandbox channel
           with a healthy token was announced as expired from a leftover live
           date. partials/integration-banners.blade.php asks ChannelWorkspace
           instead, which is the same object the card, the head and the
           channel sidebar read, so the four cannot disagree. This method
           keeps operational notices only. */
        /* Any paused store raises the banner (multi-store S3, #194); the
         * legacy storeless key still counts during the transition. */
        $paused = Cache::get('shopee_sync_paused');
        $pausedLabel = null;
        foreach (ShopeeSetting::query()->where('enabled', true)->orderBy('id')->get(['id', 'store_name']) as $ps) {
            if ($storePaused = Cache::get('shopee_sync_paused:' . $ps->id)) {
                $paused = $storePaused;
                $pausedLabel = $ps->store_name;
                break;
            }
        }
        if ($paused) {
            $banners[] = [
                'label'    => 'Shopee' . ($pausedLabel ? " ({$pausedLabel})" : '') . ': Order sync paused — ' . $paused,
                'severity' => 'error',
                'href'     => route('ext.shopee.index'),
            ];
        }

        return $banners;
    }

    public function resolveSourceLabel(string $source): ?string
    {
        return $source === 'shopee' ? 'Shopee' : null;
    }

    /**
     * Per-store reports (the owner, 2026-09-05): every store as a filter
     * choice, paused ones included. A deleted store is simply gone.
     */
    public function availableStoreOptions(): array
    {
        if (! Schema::hasTable('shopee_settings')) {
            return [];
        }

        return ShopeeSetting::query()->orderBy('id')->get(['id', 'store_name'])->map(fn ($s) => [
            'key'        => \App\Support\StoreKey::of('shopee', (int) $s->id),
            'label'      => $s->store_name ?: ('Store #' . $s->id),
            'channel'    => 'Shopee',
            'source'     => 'shopee',
            'store_id'   => (int) $s->id,
            'permission' => 'view_shopee/order',
        ])->all();
    }

    public function availableSourceOptions(): array
    {
        return [[
            'value'       => 'shopee',
            'label'       => 'Shopee',
            'channel'     => 'Shopee',
            'badge_class' => 'badge-orange',
            'chart_color' => '#ee4d2d',
        ]];
    }

    /**
     * Claim the bare "/" URL when the request carries Shopee's OAuth
     * callback params. Shopee's authorization flow requires a domain-
     * level redirect URI (no path), so the bare APP_URL is registered
     * as the callback in the Shopee developer console. When neither
     * `code` nor `shop_id` is present, defer so core's HomeController
     * can fall through to /dashboard.
     */
    public function handleRootRoute(Request $request): mixed
    {
        if (!$request->query('code') && !$request->query('shop_id')) {
            return null;
        }

        return app(\Extensions\shopee\Controllers\ShopeeController::class)
            ->root($request, app(ShopeeClient::class));
    }

    use RevealsCredentials;

    /**
     * Readable back, and nothing else is.
     *
     * Public identifiers are deliberately absent: app keys, partner ids and
     * shop ids are not secrets, so they are printed in the settings page and
     * there is nothing to reveal. Only what is genuinely hidden appears here.
     */
    public function revealableCredentials(): array
    {
        return [
            'partner_key' => 'Partner key',
            'access_token' => 'Access token',
            'refresh_token' => 'Refresh token',
            'sandbox_partner_key' => 'Sandbox partner key',
            'sandbox_access_token' => 'Sandbox access token',
            'sandbox_refresh_token' => 'Sandbox refresh token',
            'push_partner_key' => 'Push partner key',
        ];
    }

    protected function credentialSettingsModel(): string
    {
        return \Extensions\shopee\Models\ShopeeSetting::class;
    }

    public function credentialManagePermission(): string
    {
        return 'manage_shopee/settings';
    }

    /**
     * Phase B (#184): the one answer to "where does this product stand on
     * Shopee?", for core surfaces that render listing state.
     */
    public function listingStates(array $productIds): array
    {
        return app(\Extensions\shopee\Services\Shopee\ShopeeListingStates::class)->forProducts($productIds);
    }


    /** Every catalog product this shop currently lists (#197). */
    public function listedProductIds(): array
    {
        if (! Schema::hasTable('shopee_product_links')) {
            return [];
        }

        return \Extensions\shopee\Models\ShopeeProductLink::query()
            ->whereNotNull('shopee_item_id')
            ->distinct()->pluck('product_id')->map(fn ($v) => (int) $v)->all();
    }

    public function listingUrls(array $productIds): array
    {
        $out = [];
        foreach ($productIds as $pid) {
            $out[(int) $pid] = route('ext.shopee.listings.edit', (int) $pid);
        }

        return $out;
    }

    // ── Webhooks (Phase E, #184) ────────────────────────────────────

    public function webhookChannel(): string
    {
        return 'shopee';
    }

    /**
     * Shopee signs each push HMAC-SHA256(partner_key, url + '|' + body)
     * into the Authorization header. Checked against both the bare and
     * the full URL, since the registered callback may carry a query.
     */
    public function verifyWebhook(\Illuminate\Http\Request $request): bool
    {
        /* Multi-store S5 (#194): the push is signed with a PARTNER key,
         * and each store may sit under its own partner app. The store the
         * payload names (shop_id) offers its key first; every other
         * enabled store's key is tried after it, because stores under one
         * partner app share a key and the payload's store may be the
         * second row holding it. An unmatched signature stays a refusal. */
        $received = (string) $request->header('Authorization', '');
        if ($received === '') {
            return false;
        }

        $keys = [];
        $named = $this->webhookStore((array) json_decode((string) $request->getContent(), true));
        $stores = ShopeeSetting::query()->where('enabled', true)->orderBy('id')->get();
        foreach (($named ? collect([$named])->merge($stores) : $stores) as $store) {
            $auth = ShopeeSetting::activeAuth($store->decrypted());
            $key = (string) ($auth['partner_key'] ?? '');
            if ($key !== '') {
                $keys[$key] = true;
            }
        }

        foreach (array_keys($keys) as $key) {
            foreach ([$request->url(), $request->fullUrl()] as $url) {
                if (hash_equals(hash_hmac('sha256', $url . '|' . $request->getContent(), $key), $received)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** The store a Shopee push names, by the shop_id every push carries. */
    private function webhookStore(array $payload): ?ShopeeSetting
    {
        $shopId = (int) ($payload['shop_id'] ?? 0);
        if ($shopId <= 0) {
            return null;
        }

        return ShopeeSetting::query()
            ->where('shop_id', $shopId)
            ->orWhere('sandbox_shop_id', $shopId)
            ->orderBy('id')->first();
    }

    /**
     * Named by SHAPE, not by Shopee's numeric code list - the shape is
     * what the handler needs, and an unrecognised code still gets an
     * honest name in the inbox.
     */
    public function webhookEventType(array $payload): ?string
    {
        $data = (array) ($payload['data'] ?? []);
        if (isset($data['item_id'], $data['item_status'])) {
            return 'item_status';
        }
        if (isset($data['ordersn'])) {
            return 'order_status';
        }

        return isset($payload['code']) ? 'code_' . (int) $payload['code'] : null;
    }

    /**
     * v1 applies only MIRRORS - surfaces with no side effects. An order
     * push is recorded, never written: order status transitions carry
     * stock movements, and those belong to the order sync's own path.
     */
    public function handleWebhookEvent(\App\Models\ChannelWebhookEvent $event): string
    {
        $data = (array) ($event->payload['data'] ?? []);

        if ($event->event_type === 'item_status') {
            $itemId = (int) $data['item_id'];
            $status = strtoupper(trim((string) $data['item_status']));
            if ($status === '') {
                return 'item push without a status; nothing applied';
            }
            // The push names its shop; the mirror write stays inside that
            // store's links (multi-store S5, #194). A push naming no shop
            // this install holds still updates by item id alone - Shopee
            // item ids are platform-unique, so that is narrow already.
            $store = $this->webhookStore((array) $event->payload);
            $n = ShopeeProductLink::query()
                ->when($store !== null, fn ($q) => $q->where('shopee_setting_id', $store->id))
                ->where('shopee_item_id', $itemId)
                ->update(['live_status' => $status, 'live_checked_at' => now()]);

            return $n > 0
                ? "item {$itemId} mirror -> {$status} ({$n} link row" . ($n === 1 ? '' : 's') . ')'
                : "item {$itemId} is not linked here; nothing to refresh";
        }

        if ($event->event_type === 'order_status') {
            $sn = (string) ($data['ordersn'] ?? '');
            $status = (string) ($data['status'] ?? '');

            return "order {$sn} moved to {$status} on Shopee; the next order sync applies it (stock rides that path)";
        }

        return 'stored; no handler for ' . ($event->event_type ?? 'this shape') . ' yet';
    }

    // ── A catalog delete forgets the product on this channel, inside VentaSync only (ProductRemover, 2026-09-07) ──

    public function productPresence(array $productIds): array
    {
        return app(\Extensions\shopee\Services\Shopee\ShopeeProductRemover::class)->presence($productIds);
    }

    public function removeProduct(int $productId): array
    {
        return app(\Extensions\shopee\Services\Shopee\ShopeeProductRemover::class)->remove($productId);
    }

    public function strandedProductIds(): array
    {
        return app(\Extensions\shopee\Services\Shopee\ShopeeProductRemover::class)->stranded();
    }
}
