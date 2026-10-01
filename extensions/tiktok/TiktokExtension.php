<?php

namespace Extensions\tiktok;

use App\Extensions\ExtensionProvider;
use App\Integrations\Contracts\DashboardContributor;
use App\Integrations\Contracts\LayoutBannerContributor;
use App\Integrations\Contracts\MarketplaceSourceOptionsProvider;
use App\Integrations\Contracts\MobileMarketplaceProvider;
use App\Integrations\Contracts\OrderFeesContributor;
use App\Integrations\Contracts\SkuSyncContributor;
use App\Models\Catalog\Order;
use Illuminate\Http\Request;
use App\Integrations\Dto\RecentOrder;
use App\Integrations\Dto\TopProduct;
use App\Integrations\IntegrationCard;
use App\Integrations\IntegrationProvider;
use App\Integrations\IntegrationRegistry;
use App\Integrations\MenuItem;
use App\Integrations\OrderTab;
use Extensions\tiktok\Models\TikTokApiLog;
use Extensions\tiktok\Models\TikTokOrder;
use Extensions\tiktok\Models\TikTokProductGroupProduct;
use Extensions\tiktok\Models\TikTokSetting;
use Extensions\tiktok\Services\TikTok\TikTokClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

use App\Integrations\Contracts\CredentialRevealer;
use App\Integrations\Concerns\RevealsCredentials;

class TiktokExtension extends ExtensionProvider implements IntegrationProvider, SkuSyncContributor, DashboardContributor, OrderFeesContributor, \App\Integrations\Contracts\MarketplaceFeeSource, MobileMarketplaceProvider, LayoutBannerContributor, MarketplaceSourceOptionsProvider, CredentialRevealer, \App\Integrations\Contracts\ListingStateSource, \App\Integrations\Contracts\WebhookReceiver, \App\Integrations\Contracts\StoreOptionsProvider, \App\Integrations\Contracts\ProductRemover, \App\Integrations\Contracts\VariationForgetter, \App\Integrations\Contracts\SaleLinesSource, \App\Integrations\Contracts\MarketplaceOrderRefRenderer
{
    private const MOBILE_TAB_STATUS_MAP = [
        'UNPAID'              => ['UNPAID'],
        'PENDING'             => ['AWAITING_SHIPMENT', 'AWAITING_COLLECTION'],
        'SHIPPING'            => ['IN_TRANSIT'],
        'DELIVERED_COMPLETED' => ['DELIVERED', 'COMPLETED'],
        'CANCELLED'           => ['CANCELLED'],
    ];

    private const MOBILE_PENDING_SUB_MAP = [
        'to_pack'     => ['AWAITING_SHIPMENT'],
        'to_handover' => ['AWAITING_COLLECTION'],
    ];

    protected string $id = 'tiktok';

    public function boot(): void
    {
        parent::boot();

        // Multi-store (the owner 2026-09-05): let every route() call fill {store}
        // even outside a TikTok store request (hub, fulfilment tabs, the OAuth
        // callback view, test code). The route-store binding overrides per-request.
        TikTokSetting::syncUrlDefault();

        // Registered in every runtime, not only the console: the Automations
        // tab's Run now calls Artisan inside a web request (live, 2026-09-07).
        $this->commands([
            \Extensions\tiktok\Commands\TikTokPushStock::class,
            \Extensions\tiktok\Commands\TikTokPushPrice::class,
            \Extensions\tiktok\Commands\TikTokRefreshListingStatus::class,
            \Extensions\tiktok\Commands\TikTokRefreshToken::class,
            \Extensions\tiktok\Commands\TikTokSyncOrders::class,
            \Extensions\tiktok\Commands\TikTokSyncPayouts::class,
            \Extensions\tiktok\Commands\TikTokBackfillPayouts::class,
        ]);

        $this->app->make(IntegrationRegistry::class)->register($this);
    }

    /** A deleted catalog variation is forgotten here too (VariationForgetter, 2026-09-07). */
    public function forgetVariations(int $productId, array $skus): void
    {
        \Extensions\tiktok\Services\TikTok\TikTokVariationForgetter::forget($productId, $skus);
    }

    public function integrationId(): string
    {
        return $this->id;
    }

    public function integrationCards(): array
    {
        /* Multi-store (the owner 2026-09-05): the converted shape Shopee, Venta,
         * OpenCart, Shopify and Lazada wear. One card for the channel; one
         * StoreLink per settings row with the store's own menu, every item
         * carrying its {store}. The card-level menu is empty. A store still
         * being configured stays visible as setup_pending. */
        $stores = [];
        foreach ($this->allStores() as $store) {
            $p = ['store' => (int) $store->id];
            $stores[] = new \App\Integrations\StoreLink(
                id: $this->id . ':' . $store->id,
                label: $store->store_name ?: ('Store #' . $store->id),
                menu: [
                    new MenuItem('Orders', 'ext.tiktok.orders.index', 'view_tiktok/order', null, $p, 'Sell'),
                    new MenuItem('Returns', 'ext.tiktok.orders.returns', 'view_tiktok/order', null, $p, 'Sell'),
                    new MenuItem('Coupons', 'ext.tiktok.coupons.index', 'view_tiktok/coupon', null, $p, 'Sell'),
                    new MenuItem('Listings', 'ext.tiktok.products.index', 'view_tiktok/product', null, $p, 'Catalog'),
                    new MenuItem('Product Groups', 'ext.tiktok.product-groups.index', 'view_tiktok/product_group', null, $p, 'Catalog'),
                    new MenuItem('Description Templates', 'ext.tiktok.description-templates.index', 'view_tiktok/description_template', null, $p, 'Catalog'),
                    new MenuItem('Watermarks', 'ext.tiktok.watermarks.index', 'view_tiktok/watermark_template', null, $p, 'Catalog'),
                    new MenuItem('Categories', 'ext.tiktok.categories.index', 'view_tiktok/product_group', null, $p, 'Catalog'),
                    new MenuItem('Import', 'ext.tiktok.products.import', 'view_tiktok/product', null, $p, 'Catalog'),
                    new MenuItem('Settings', 'ext.tiktok.index', 'view_tiktok/settings', null, $p, 'Channel', children: [
                        ['label' => 'Connection', 'section' => 'connection'],
                        ['label' => 'Status mapping', 'section' => 'status'],
                        ['label' => 'API explorer', 'section' => 'explorer', 'permission' => 'manage_tiktok/settings'],
                        ['label' => 'API log', 'section' => 'logs'],
                        ['label' => 'Automations', 'section' => 'automations', 'permission' => 'manage_tiktok/settings'],
                    ]),
                ],
                status: $store->enabled ? 'active' : 'setup_pending',
            );
        }

        return [
            new IntegrationCard(
                id: $this->id,
                name: 'TikTok Shop',
                tagline: 'TikTok Shop integration. OAuth, product sync, orders.',
                icon: 'video',
                // Black, not the pink. TikTok's mark is black-first and the
                // legacy build used #1e1e1e here; the pink also measured
                // deltaE 19 from this world's danger red, so a filled TikTok
                // button read as a destructive one.
                accent: '#1e1e1e',
                // Card + menu use the VIEW tier so read-only operators still see
                // TikTok in the Integrations hub and contextual sidebar. The
                // 'manage implies view' cascade in PermissionHierarchy means
                // managers continue to see these entries.
                // The dashboard's own derived key (tiktok/dashboard), not the
                // legacy view_tiktok: the card decides who sees this channel
                // in the hub and the nav, and that must be the same answer
                // the dashboard's door gives, or the menu offers a 404.
                permission: 'view_tiktok/dashboard',
                // No card-level menu: TikTok has no channel-global page - every
                // settings route now needs a {store}. The per-store StoreLinks
                // above carry Settings, so a card-level link to ext.tiktok.index
                // would only throw the moment no store is enabled.
                menu: [],
                stores: $stores,
                addStore: new \App\Integrations\AddStoreAction(
                    route: 'ext.tiktok.stores.store',
                    permission: 'manage_tiktok/settings',
                    label: 'Add store',
                ),
            ),
        ];
    }

    /**
     * Every settings row, enabled or not - the Integrations UI keeps a
     * freshly-created, still-disabled store visible so the operator can finish
     * configuring it.
     *
     * @return iterable<\Extensions\tiktok\Models\TikTokSetting>
     */
    protected function allStores(): iterable
    {
        try {
            if (! Schema::hasTable('tiktok_settings')) {
                return [];
            }

            return TikTokSetting::query()->orderBy('id')->get();
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function orderTabs(): array
    {
        /* Multi-store (the owner 2026-09-05, parity with Lazada/Shopee): one tab
         * per enabled store, counters scoped to the store's own rows. With a
         * single store the tab keeps the channel's name - the store names
         * take over the moment a second store exists and the name has to
         * distinguish. */
        if (! Schema::hasTable('tiktok_settings')) {
            return [];
        }
        $stores = TikTokSetting::query()->where('enabled', true)->orderBy('id')->get(['id', 'store_name']);

        return $stores->map(fn ($store) => $this->buildOrderTab(
            (int) $store->id,
            \App\Support\StoreLabel::of('TikTok', $store->store_name ?: ('Store #' . $store->id))
        ))->all();
    }

    private function buildOrderTab(int $storeId, string $label): OrderTab
    {
        $tiktokStatusCount = function (array $statuses) use ($storeId): int {
            if (!Schema::hasTable('tiktok_orders')) {
                return 0;
            }

            return TikTokOrder::query()->where('tiktok_setting_id', $storeId)->whereIn('status', $statuses)->count();
        };

        return
            new OrderTab(
                id: $this->id . ':' . $storeId,
                label: $label,
                icon: 'video',
                accent: '#1e1e1e',
                routeName: 'ext.tiktok.orders.index',
                // OrderTab visibility uses view tier so a TikTok Orders Viewer
                // still sees the marketplace's orders tab on the unified Orders
                // workspace.
                permission: 'view_tiktok/order',
                routeParams: ['store' => $storeId],
                unprocessedCounter: function () use ($storeId) {
                    if (!Schema::hasTable('tiktok_orders')) {
                        return 0;
                    }
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
                    return TikTokOrder::query()
                        ->where('tiktok_setting_id', $storeId)
                        ->whereIn('status', ['AWAITING_SHIPMENT', 'AWAITING_COLLECTION'])
                        ->count();
                },
                /* The steps while the seller holds the parcel, each counted alone:
                 * the fulfilment card leads with To Pack and prints To Handover beside
                 * it, which the staff count against the shelf. The statuses are the
                 * orders panel's own sub-tab map, so the card and the tab agree. */
                stages: [
                    ['key' => 'to_pack', 'label' => 'to pack', 'counter' => fn () => $tiktokStatusCount(['AWAITING_SHIPMENT'])],
                    ['key' => 'to_handover', 'label' => 'to handover', 'counter' => fn () => $tiktokStatusCount(['AWAITING_COLLECTION'])],
                ],
                dailyOrdersCounter: function () use ($storeId) {
                    if (!Schema::hasTable('tiktok_orders')) {
                        return 0;
                    }
                    return TikTokOrder::query()
                        ->where('tiktok_setting_id', $storeId)
                        ->where('order_created_at', '>=', now()->startOfDay())
                        ->count();
                },
                dailyRevenueCounter: function () use ($storeId) {
                    if (!Schema::hasTable('tiktok_orders') || !Schema::hasTable('tiktok_order_products')) {
                        return 0.0;
                    }
                    return (float) DB::table('tiktok_order_products as p')
                        ->join('tiktok_orders as o', 'o.id', '=', 'p.tiktok_order_id')
                        ->where('o.tiktok_setting_id', $storeId)
                        ->where('o.order_created_at', '>=', now()->startOfDay())
                        ->sum(DB::raw('p.quantity * COALESCE(p.sale_price, p.item_price)'));
                },
                topProductsCallback: function (int $limit) use ($storeId) {
                    if (!Schema::hasTable('tiktok_orders') || !Schema::hasTable('tiktok_order_products')) {
                        return [];
                    }
                    return DB::table('tiktok_order_products as p')
                        ->join('tiktok_orders as o', 'o.id', '=', 'p.tiktok_order_id')
                        ->where('o.tiktok_setting_id', $storeId)
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
                            DB::raw('SUM(p.quantity * COALESCE(p.sale_price, p.item_price)) as revenue'),
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
                    if (!Schema::hasTable('tiktok_orders')) {
                        return [];
                    }
                    $orders = TikTokOrder::query()
                        ->where('tiktok_setting_id', $storeId)
                        ->orderByDesc('order_created_at')
                        ->limit($limit)
                        ->get(['id', 'order_id', 'status', 'buyer_name', 'order_created_at']);

                    $totals = DB::table('tiktok_order_products')
                        ->whereIn('tiktok_order_id', $orders->pluck('id'))
                        ->select('tiktok_order_id', DB::raw('SUM(quantity * COALESCE(sale_price, item_price)) as total'))
                        ->groupBy('tiktok_order_id')
                        ->pluck('total', 'tiktok_order_id');

                    return $orders->map(fn ($o) => new RecentOrder(
                        reference: (string) $o->order_id,
                        customerName: $o->buyer_name,
                        total: (float) ($totals[$o->id] ?? 0),
                        statusLabel: (string) $o->status,
                        orderedAt: $o->order_created_at,
                        url: route('ext.tiktok.orders.show', $o->id),
                    ))->all();
                },
            );
    }

    /**
     * React to a SKU change on a catalog product by pushing the new
     * seller_sku to every linked TikTok shop product. Uses the partial-
     * edit endpoint via TikTokClient::editProduct — only the changed
     * `skus[]` payload is sent, the rest of the product stays untouched.
     */
    public function pushSkuChanges(int $productId, array $skuChanges): ?string
    {
        if (empty($skuChanges['product_sku']) || !Schema::hasTable('tiktok_product_group_products')) {
            return null;
        }

        $newSku = $skuChanges['product_sku']['new'];

        // Only the store whose credentials send the edit below.
        $links = TikTokProductGroupProduct::query()->onStore()->where('product_id', $productId)
            ->whereNotNull('tiktok_product_id')
            ->whereNotNull('tiktok_sku_id')
            ->get();

        if ($links->isEmpty()) {
            return null;
        }

        $setting = TikTokSetting::defaultStore();
        if (!$setting || !$setting->access_token || !$setting->app_key) {
            return null;
        }

        $client = new TikTokClient();
        $appKey = (string) $setting->app_key;
        $appSecret = (string) $setting->app_secret;
        $token = (string) $setting->access_token;
        $shopCipher = (string) ($setting->shop_cipher ?? '') ?: null;

        $results = [];
        foreach ($links->groupBy('tiktok_product_id') as $tiktokProductId => $linksForProduct) {
            $skusPayload = $linksForProduct->map(fn ($l) => [
                'id' => (string) $l->tiktok_sku_id,
                'seller_sku' => $newSku,
            ])->values()->all();

            try {
                $result = $client->editProduct($appKey, $appSecret, $token, (string) $tiktokProductId, [
                    'skus' => $skusPayload,
                ], $shopCipher);

                TikTokApiLog::safeCreate([
                    'pack'            => 'tiktok.product.edit.sku_sync',
                    'method'          => 'PUT',
                    'api_path'        => '/product/202309/products/' . $tiktokProductId,
                    'auth_required'   => true,
                    'request_params'  => ['skus' => $skusPayload],
                    'response_status' => (int) ($result['status'] ?? 0),
                    'ok'              => (bool) ($result['ok'] ?? false),
                    'response_body'   => $result['body'] ?? [],
                    'user_id'         => auth()->id(),
                ]);

                $apiCode = (int) ($result['body']['code'] ?? -1);
                $apiOk = ($result['ok'] ?? false) && $apiCode === 0;
                $results[] = "Product {$tiktokProductId}: " . ($apiOk ? 'OK' : 'Failed');
            } catch (\Throwable $e) {
                Log::warning('TikTok SKU edit failed', [
                    'tiktok_product_id' => $tiktokProductId,
                    'error' => $e->getMessage(),
                ]);
                $results[] = "Product {$tiktokProductId}: Error";
            }
        }

        return empty($results) ? null : 'TikTok: ' . implode(', ', $results);
    }

    /**
     * TikTok widgets for the core dashboard: pending order count and
     * sync-status row pulled from the settings table.
     */
    public function dashboardData(): array
    {
        $data = [
            'tiktokPending' => 0,
            'tiktokSyncStatus' => null,
        ];

        if (Schema::hasTable('tiktok_settings')) {
            $ttSetting = TikTokSetting::defaultStore();
            if ($ttSetting) {
                /* The listing troubles ride the chip (Phase D, #184), the
                 * same two numbers Shopee's and Lazada's carry. A failure
                 * here must not take the dashboard down. */
                $flags = null;
                $attention = 0;
                try {
                    $sum = app(\Extensions\tiktok\Services\TikTok\TikTokListingStates::class)->summary();
                    $attention = (int) $sum['attention'];
                    /* Each figure carries where it goes (#240): the Listings page
                       filtered by the same query that counted it. The words are
                       written once, in App\Integrations\Listings\ListingTrouble,
                       so the chip and the page it lands on say the same thing. */
                    $flags = \App\Integrations\Listings\ListingTrouble::figures($sum, fn (string $bucket) => route('ext.tiktok.products.index', ['store' => $ttSetting->id, 'state' => $bucket]));
                } catch (\Throwable) {
                    // the chip simply carries no flags line
                }

                $data['tiktokSyncStatus'] = (object) [
                    'order_sync_at'  => $ttSetting->last_order_sync_at,
                    'push_stock_at'  => $ttSetting->last_stock_push_at,
                    'expires_at'     => $ttSetting->expires_at,
                    'listing_flags'  => $flags,
                    'listing_attention' => $attention,
                ];
            }
        }

        if (Schema::hasTable('tiktok_orders')) {
            $data['tiktokPending'] = (int) DB::table('tiktok_orders')
                // To Ship = to pack + to handover, matching unprocessedCount().
                ->whereIn('status', ['AWAITING_SHIPMENT', 'AWAITING_COLLECTION'])
                ->count();
        }


        // Per-store figures ride alongside the channel-level ones.
        $data += $this->perStoreDashboardFigures();

        return $data;
    }

    /**
     * Per-store dashboard figures (#194, 2026-09-07): with two stores of one
     * channel the strip used to show one blended row on both, because the
     * contribution was keyed by channel. Each store now reports its own
     * waiting count and its own freshness under its tab id ("tiktok:2"),
     * which the dashboard prefers over the channel-level key.
     *
     * @return array{channelPending: array<string, int>, channelSyncStatuses: array<string, object>}
     */
    private function perStoreDashboardFigures(): array
    {
        $pending = [];
        $statuses = [];
        if (! Schema::hasTable('tiktok_settings')) {
            return ['channelPending' => $pending, 'channelSyncStatuses' => $statuses];
        }

        foreach (TikTokSetting::query()->orderBy('id')->get() as $store) {
            $key = \App\Support\StoreKey::of('tiktok', (int) $store->id);
            if (Schema::hasTable('tiktok_orders')) {
                $pending[$key] = (int) DB::table('tiktok_orders')
                    ->where('tiktok_setting_id', $store->id)
                    ->whereIn('status', ['AWAITING_SHIPMENT', 'AWAITING_COLLECTION'])
                    ->count();
            }
            /* The listing engine scopes to the bound store, so each store's
             * flags are its own; a failure must not take the strip down. */
            $flags = null;
            $attention = 0;
            $previous = app()->bound('tiktok.route-store') ? app('tiktok.route-store') : null;
            try {
                app()->instance('tiktok.route-store', $store);
                $sum = app(\Extensions\tiktok\Services\TikTok\TikTokListingStates::class)->summary();
                $attention = (int) $sum['attention'];
                /* Each figure carries where it goes (#240): the Listings page
                   filtered by the same query that counted it. The words are
                   written once, in App\Integrations\Listings\ListingTrouble,
                   so the chip and the page it lands on say the same thing. */
                $flags = \App\Integrations\Listings\ListingTrouble::figures($sum, fn (string $bucket) => route('ext.tiktok.products.index', ['store' => $store->id, 'state' => $bucket]));
            } catch (\Throwable) {
                // this store's chip simply carries no flags line
            } finally {
                if ($previous !== null) {
                    app()->instance('tiktok.route-store', $previous);
                } else {
                    app()->forgetInstance('tiktok.route-store');
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
     * TikTok fees come from tiktok_orders.fees (commission, transaction fee,
     * shipping fee).
     */
    /**
     * What TikTok Shop charged for this order, in core's four buckets. Its
     * delivery charge is shipping_fee, which sat in the Fees column until
     * 2026-09-08; it publishes no payment-side deduction.
     */
    public function renderOrderRef(string $marketplaceSource, string $marketplaceOrderId): ?array
    {
        if ($marketplaceSource !== 'tiktok') {
            return null;
        }
        $row = \Extensions\tiktok\Models\TikTokOrder::query()->withoutGlobalScope('tiktokStore')->where('order_id', $marketplaceOrderId)->first(['id', 'tiktok_setting_id']);

        return ['display' => $marketplaceOrderId, 'url' => $row && $row->tiktok_setting_id ? route('ext.tiktok.orders.show', ['store' => $row->tiktok_setting_id, 'id' => $row->id]) : null];
    }

    public function saleLinesForOrder(int $coreOrderId, float $itemsSubtotal, float $shipping): ?array
    {
        $order = \Extensions\tiktok\Models\TikTokOrder::query()->withoutGlobalScope('tiktokStore')->where('catalog_order_id', $coreOrderId)->first();
        if (! $order) {
            return null;
        }
        $raw = is_array($order->raw) ? $order->raw : [];

        return \Extensions\tiktok\Services\TikTokSaleLines::lines(is_array($raw['payment'] ?? null) ? $raw['payment'] : [], $itemsSubtotal, $shipping);
    }

    public function feeBucketsForOrder(int $coreOrderId): ?array
    {
        if (! Schema::hasTable('tiktok_orders')) {
            return null;
        }

        $row = \Extensions\tiktok\Models\TikTokOrder::query()->withoutGlobalScope('tiktokStore')
            ->where('catalog_order_id', $coreOrderId)->first();
        if (! $row) {
            return null;
        }

        $payload = \App\Integrations\Orders\FeePayload::decode($row->fees);
        if (\App\Integrations\Orders\FeePayload::isEmpty($payload)) {
            return null;
        }

        // The seller's discount beyond the markdown already inside the item prices.
        $payment = is_array($row->raw['payment'] ?? null) ? $row->raw['payment'] : [];
        $sellerDiscount = 0.0;
        foreach (\Extensions\tiktok\Services\TikTokSaleLines::lines($payment, 0, 0) as $line) {
            if ($line['code'] === \App\Services\Orders\SaleLines\AbstractSaleLines::SELLER_DISCOUNT) {
                $sellerDiscount = abs($line['value']);
            }
        }

        return [
            'commission' => \App\Integrations\Orders\FeePayload::amount($payload, 'commission'),
            'payment' => 0.0,
            'shipping' => \App\Integrations\Orders\FeePayload::amount($payload, 'shipping_fee'),
            'other' => \App\Integrations\Orders\FeePayload::amount($payload, 'transaction_fee'),
            'voucher' => $sellerDiscount,
        ];
    }

    public function feesForOrder(Order $order): ?array
    {
        if ((string) $order->marketplace_source !== 'tiktok') {
            return null;
        }

        $mktOrderId = (string) $order->marketplace_order_id;
        if ($mktOrderId === '' || !Schema::hasTable('tiktok_orders')) {
            return null;
        }

        $ttOrder = TikTokOrder::where('order_id', $mktOrderId)->first();
        if (!$ttOrder || empty($ttOrder->fees)) {
            return ['total' => 0, 'items' => [], 'source' => 'tiktok'];
        }

        $fees = is_array($ttOrder->fees) ? $ttOrder->fees : [];
        $items = [];
        $total = 0;

        $commission  = abs((float) ($fees['commission'] ?? 0));
        $txnFee      = abs((float) ($fees['transaction_fee'] ?? 0));
        $shippingFee = abs((float) ($fees['shipping_fee'] ?? 0));

        if ($commission > 0)  { $items[] = ['label' => 'Commission',       'amount' => $commission];  $total += $commission; }
        if ($txnFee > 0)      { $items[] = ['label' => 'Transaction Fee', 'amount' => $txnFee];      $total += $txnFee; }
        if ($shippingFee > 0) { $items[] = ['label' => 'Shipping Fee',    'amount' => $shippingFee]; $total += $shippingFee; }

        return ['total' => round($total, 2), 'items' => $items, 'source' => 'tiktok'];
    }

    // ── Mobile API ─────────────────────────────────────────────

    public function mobilePlatformSlug(): string
    {
        return 'tiktok';
    }

    public function mobileIndexResponse(Request $request): array
    {
        $tab = strtoupper((string) $request->query('tab', 'ALL'));
        $search = trim((string) $request->query('q', ''));
        $pendingSub = (string) $request->query('pending_sub', '');
        if ($tab !== 'PENDING' || !array_key_exists($pendingSub, self::MOBILE_PENDING_SUB_MAP)) {
            $pendingSub = '';
        }

        $base = TikTokOrder::query();
        $query = TikTokOrder::query();

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
                $q->where('order_id', 'like', $like)->orWhere('buyer_name', 'like', $like);
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
                'order_id'        => (string) $order->order_id,
                'status'          => $order->status,
                'buyer_name'      => $order->buyer_name ?? '',
                'total_amount'    => isset($raw['payment']['total_amount']) ? (float) $raw['payment']['total_amount'] : null,
                'currency'        => $raw['payment']['currency'] ?? \App\Support\Money::defaultSymbol(),
                'items_count'     => $order->products()->count(),
                'product_image'   => $productImage ?: null,
                'payment_method'  => $raw['payment']['payment_method_name'] ?? null,
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
        $order = TikTokOrder::with('products')->find($id);
        if (!$order) {
            return null;
        }

        $raw = $order->raw ?? [];
        $payment = $raw['payment'] ?? [];
        $addr = $raw['recipient_address'] ?? [];

        $products = $order->products
            ->groupBy(fn ($p) => ($p->sku ?? '') . '|' . ($p->variation ?? ''))
            ->map(function ($group) {
                $first = $group->first();
                return [
                    'name'      => $first->name,
                    'sku'       => $first->sku ?? '',
                    'variation' => $first->variation ?? '',
                    'quantity'  => $group->sum('quantity'),
                    'price'     => $first->sale_price ?? $first->item_price ?? 0,
                    'image'     => $first->image ?? '',
                ];
            });

        return [
            'order_id'          => (string) $order->order_id,
            'status'            => $order->status,
            'buyer_name'        => $order->buyer_name ?? '',
            'total_amount'      => isset($payment['total_amount']) ? (float) $payment['total_amount'] : null,
            'currency'          => $payment['currency'] ?? \App\Support\Money::defaultSymbol(),
            'payment_method'    => $payment['payment_method_name'] ?? '',
            'shipping_provider' => $raw['shipping_provider'] ?? $raw['shipping_provider_id'] ?? '',
            'tracking_number'   => $raw['tracking_number'] ?? '',
            'shipping_name'     => $addr['name'] ?? $order->buyer_name ?? '',
            'shipping_phone'    => $addr['phone_number'] ?? $addr['phone'] ?? '',
            'shipping_address'  => $addr['full_address']
                ?? trim(implode(', ', array_filter([
                    $addr['address_detail'] ?? '',
                    $addr['district'] ?? '',
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
        /* Credential state is NOT answered here any more. It read
           settings.expires_at directly - the LIVE column - while these
           installs select their credential by `mode`, so a sandbox channel
           with a healthy token was announced as expired from a leftover live
           date. partials/integration-banners.blade.php asks ChannelWorkspace
           instead, which is the same object the card, the head and the
           channel sidebar read, so the four cannot disagree. This method
           keeps operational notices only. */
        return [];
    }

    public function resolveSourceLabel(string $source): ?string
    {
        return $source === 'tiktok' ? 'TikTok' : null;
    }

    /**
     * Per-store reports (the owner, 2026-09-05): every store as a filter
     * choice, paused ones included. A deleted store is simply gone.
     */
    public function availableStoreOptions(): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('tiktok_settings')) {
            return [];
        }

        return \Extensions\tiktok\Models\TikTokSetting::query()->orderBy('id')->get(['id', 'store_name'])->map(fn ($s) => [
            'key'        => \App\Support\StoreKey::of('tiktok', (int) $s->id),
            'label'      => $s->store_name ?: ('Store #' . $s->id),
            'channel'    => 'TikTok',
            'source'     => 'tiktok',
            'store_id'   => (int) $s->id,
            'permission' => 'view_tiktok/order',
        ])->all();
    }

    public function availableSourceOptions(): array
    {
        return [[
            'value'       => 'tiktok',
            'label'       => 'TikTok',
            'channel'     => 'TikTok',
            'badge_class' => 'badge-dark',
            'chart_color' => '#1e1e1e',
        ]];
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
            'app_secret' => 'App secret',
            'access_token' => 'Access token',
            'refresh_token' => 'Refresh token',
            'sandbox_app_secret' => 'Sandbox app secret',
            'sandbox_access_token' => 'Sandbox access token',
            'sandbox_refresh_token' => 'Sandbox refresh token',
        ];
    }

    protected function credentialSettingsModel(): string
    {
        return \Extensions\tiktok\Models\TikTokSetting::class;
    }

    public function credentialManagePermission(): string
    {
        return 'manage_tiktok/settings';
    }

    /**
     * Phase D (#184): the one answer to "where does this product stand on
     * TikTok Shop?", for core surfaces that render listing state.
     */
    public function listingStates(array $productIds): array
    {
        return app(\Extensions\tiktok\Services\TikTok\TikTokListingStates::class)->forProducts($productIds);
    }


    /** Every catalog product TikTok Shop currently lists (#197). */
    public function listedProductIds(): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('tiktok_listings')) {
            return [];
        }

        return \Extensions\tiktok\Models\TikTokListing::query()
            ->whereNotNull('tiktok_product_id')->pluck('product_id')
            ->merge(\Extensions\tiktok\Models\TikTokProductGroupProduct::query()
                ->whereNotNull('tiktok_product_id')->pluck('product_id'))
            ->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    public function listingUrls(array $productIds): array
    {
        $out = [];
        foreach ($productIds as $pid) {
            $out[(int) $pid] = route('ext.tiktok.listings.edit', (int) $pid);
        }

        return $out;
    }

    // ── Webhooks (Phase E, #184) ────────────────────────────────────

    public function webhookChannel(): string
    {
        return 'tiktok';
    }

    /**
     * TikTok Shop signs each push HMAC-SHA256(app_secret, app_key + body)
     * into the Authorization header.
     */
    public function verifyWebhook(\Illuminate\Http\Request $request): bool
    {
        $setting = TikTokSetting::defaultStore()?->decrypted();
        $appKey = (string) ($setting->app_key ?? '');
        $appSecret = (string) ($setting->app_secret ?? '');
        $received = (string) $request->header('Authorization', '');
        if ($appKey === '' || $appSecret === '' || $received === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $appKey . $request->getContent(), $appSecret), $received);
    }

    /** Named by shape, so an unrecognised type still reads honestly in the inbox. */
    public function webhookEventType(array $payload): ?string
    {
        $data = (array) ($payload['data'] ?? []);
        if (isset($data['product_id']) && (isset($data['status']) || isset($data['product_status']))) {
            return 'product_status';
        }
        if (isset($data['order_id'])) {
            return 'order_status';
        }

        return isset($payload['type']) ? 'type_' . (int) $payload['type'] : null;
    }

    /**
     * v1 applies only MIRRORS - see ShopeeExtension: order transitions
     * carry stock movements, which belong to the order sync's own path.
     */
    public function handleWebhookEvent(\App\Models\ChannelWebhookEvent $event): string
    {
        $data = (array) ($event->payload['data'] ?? []);

        if ($event->event_type === 'product_status') {
            $productId = (string) $data['product_id'];
            $status = strtoupper(trim((string) ($data['status'] ?? $data['product_status'] ?? '')));
            if ($status === '') {
                return 'product push without a status; nothing applied';
            }
            $n = \Extensions\tiktok\Models\TikTokListing::query()
                ->where('tiktok_product_id', $productId)
                ->update(['live_status' => $status, 'live_checked_at' => now()]);

            return $n > 0
                ? "product {$productId} mirror -> {$status}"
                : "product {$productId} is not linked here; nothing to refresh";
        }

        if ($event->event_type === 'order_status') {
            $orderId = (string) ($data['order_id'] ?? '');
            $status = (string) ($data['order_status'] ?? $data['status'] ?? '');

            return "order {$orderId} moved to {$status} on TikTok Shop; the next order sync applies it (stock rides that path)";
        }

        return 'stored; no handler for ' . ($event->event_type ?? 'this shape') . ' yet';
    }

    // ── A catalog delete forgets the product on this channel, inside VentaSync only (ProductRemover, 2026-09-07) ──

    public function productPresence(array $productIds): array
    {
        return app(\Extensions\tiktok\Services\TikTok\TikTokProductRemover::class)->presence($productIds);
    }

    public function removeProduct(int $productId): array
    {
        return app(\Extensions\tiktok\Services\TikTok\TikTokProductRemover::class)->remove($productId);
    }

    public function strandedProductIds(): array
    {
        return app(\Extensions\tiktok\Services\TikTok\TikTokProductRemover::class)->stranded();
    }

    /**
     * TikTok's automations. Its commands work across the channel's stores
     * themselves, so there is one set for the channel. TikTok has no review
     * sync.
     */
    public function automations(): array
    {
        return [
            ['tiktok:sync-orders', 'Sync Orders', 15, 'minute', ['no_returns' => true]],
            ['tiktok:sync-orders', 'Sync Returns', 30, 'minute', ['returns' => true]],
            ['tiktok:sync-payouts', 'Sync Payouts', 1, 'hour', ['days' => \App\Support\PayoutStatus::DEFAULT_LOOKBACK_DAYS]],
            ['tiktok:push-stock', 'Push Stock', 30, 'minute'],
            ['tiktok:push-price', 'Push Price', 30, 'minute'],
            ['tiktok:refresh-token', 'Refresh Access Token', 12, 'hour'],
            ['tiktok:refresh-listing-status', 'Refresh Listing Status', 30, 'minute'],
        ];
    }
}
