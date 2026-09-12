<?php

namespace Extensions\lazada;

use App\Extensions\ExtensionProvider;
use App\Integrations\Contracts\CredentialRevealer;
use App\Integrations\Concerns\RevealsCredentials;
use App\Integrations\Contracts\DashboardContributor;
use App\Integrations\Contracts\LayoutBannerContributor;
use App\Integrations\Contracts\ListingStateSource;
use App\Integrations\Contracts\MarketplaceSourceOptionsProvider;
use App\Integrations\Contracts\MobileMarketplaceProvider;
use App\Integrations\Contracts\OrderFeesContributor;
use App\Integrations\Contracts\OrderImagesContributor;
use App\Integrations\Contracts\SkuResolver;
use App\Integrations\Contracts\SkuSyncContributor;
use Illuminate\Http\Request;
use App\Models\Catalog\Order;
use App\Integrations\Dto\RecentOrder;
use App\Integrations\Dto\TopProduct;
use App\Integrations\IntegrationCard;
use App\Integrations\IntegrationProvider;
use App\Integrations\IntegrationRegistry;
use App\Integrations\MenuItem;
use App\Integrations\OrderTab;
use Extensions\lazada\Models\LazadaApiLog;
use Extensions\lazada\Models\LazadaOrder;
use Extensions\lazada\Models\LazadaProduct;
use Extensions\lazada\Models\LazadaProductVariant;
use Extensions\lazada\Models\LazadaSetting;
use Extensions\lazada\Services\Lazada\LazadaClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class LazadaExtension extends ExtensionProvider implements IntegrationProvider, SkuResolver, SkuSyncContributor, DashboardContributor, OrderImagesContributor, OrderFeesContributor, \App\Integrations\Contracts\MarketplaceFeeSource, MobileMarketplaceProvider, LayoutBannerContributor, MarketplaceSourceOptionsProvider, CredentialRevealer, ListingStateSource, \App\Integrations\Contracts\StoreOptionsProvider, \App\Integrations\Contracts\ProductRemover, \App\Integrations\Contracts\VariationForgetter, \App\Integrations\Contracts\MarketplaceOrderRefRenderer, \App\Integrations\Contracts\SaleLinesSource
{
    private const MOBILE_TAB_STATUS_MAP = [
        'UNPAID'              => ['unpaid'],
        'PENDING'             => ['pending', 'repacked', 'packed', 'ready_to_ship'],
        'SHIPPING'            => ['shipped'],
        'DELIVERED_COMPLETED' => ['delivered'],
        'CANCELLED'           => ['canceled', 'cancelled'],
        'RETURN'              => ['returned'],
    ];

    private const MOBILE_PENDING_SUB_MAP = [
        'to_pack'     => ['pending', 'repacked'],
        'to_arrange'  => ['packed'],
        'to_handover' => ['ready_to_ship'],
    ];

    protected string $id = 'lazada';

    public function boot(): void
    {
        parent::boot();

        // Multi-store (the owner 2026-09-05): let every route() call fill {store}
        // even outside a Lazada store request (hub, fulfilment tabs, the OAuth
        // callback view, test code). ResolveLazadaStore overrides per-request.
        LazadaSetting::syncUrlDefault();

        // Registered in every runtime, not only the console: the Automations
        // tab's Run now calls Artisan inside a web request (live, 2026-09-07).
        $this->commands([
            \Extensions\lazada\Commands\LazadaPushStock::class,
            \Extensions\lazada\Commands\LazadaPushPrice::class,
            \Extensions\lazada\Commands\LazadaRefreshListingStatus::class,
            \Extensions\lazada\Commands\LazadaRefreshToken::class,
            \Extensions\lazada\Commands\LazadaSyncOrders::class,
            \Extensions\lazada\Commands\LazadaSyncReviews::class,
        ]);

        $this->app->make(IntegrationRegistry::class)->register($this);
    }

    /** A deleted catalog variation is forgotten here too (VariationForgetter, 2026-09-07). */
    public function forgetVariations(int $productId, array $skus): void
    {
        \Extensions\lazada\Services\Lazada\LazadaVariationForgetter::forget($productId, $skus);
    }

    public function integrationId(): string
    {
        return $this->id;
    }

    public function integrationCards(): array
    {
        /* Multi-store (the owner 2026-09-05): the converted shape Shopee, Venta,
         * OpenCart and Shopify wear. One card for the channel; one StoreLink
         * per settings row with the store's own menu, every item carrying its
         * {store}. The card-level menu keeps only Settings. A store still
         * being configured stays visible as setup_pending. */
        $stores = [];
        foreach ($this->allStores() as $store) {
            $p = ['store' => (int) $store->id];
            $stores[] = new \App\Integrations\StoreLink(
                id: $this->id . ':' . $store->id,
                label: $store->store_name ?: ('Store #' . $store->id),
                menu: [
                    new MenuItem('Orders', 'ext.lazada.orders.index', 'view_lazada/order', null, $p + ['tab' => 'TO_SHIP'], 'Sell'),
                    new MenuItem('Returns', 'ext.lazada.orders.returns', 'view_lazada/order', null, $p, 'Sell'),
                    new MenuItem('Vouchers', 'ext.lazada.vouchers.index', 'view_lazada/voucher', null, $p, 'Sell'),
                    new MenuItem('Listings', 'ext.lazada.products.index', 'view_lazada/product', null, $p, 'Catalog'),
                    new MenuItem('Product Groups', 'ext.lazada.product-groups.index', 'view_lazada/product_group', null, $p, 'Catalog'),
                    new MenuItem('Description Templates', 'ext.lazada.description-templates.index', 'view_lazada/description_template', null, $p, 'Catalog'),
                    new MenuItem('Categories', 'ext.lazada.categories.index', 'view_lazada/category', null, $p, 'Catalog'),
                    new MenuItem('Brands', 'ext.lazada.brands.index', 'view_lazada/brand', null, $p, 'Catalog'),
                    new MenuItem('Import', 'ext.lazada.products.import', 'view_lazada/product', null, $p, 'Catalog'),
                    new MenuItem('Settings', 'ext.lazada.index', 'view_lazada/settings', null, $p, 'Channel', children: [
                        ['label' => 'Connection', 'section' => 'connection'],
                        ['label' => 'Status mapping', 'section' => 'status'],
                        ['label' => 'API explorer', 'section' => 'explorer', 'permission' => 'manage_lazada/settings'],
                        ['label' => 'API log', 'section' => 'logs'],
                        ['label' => 'Automations', 'section' => 'automations', 'permission' => 'manage_lazada/settings'],
                    ]),
                ],
                status: $store->enabled ? 'active' : 'setup_pending',
            );
        }

        return [
            new IntegrationCard(
                id: $this->id,
                name: 'Lazada',
                tagline: 'Lazada marketplace integration. Products, orders, returns.',
                icon: 'lazada',
                accent: '#0f146d',
                // Card + menu use the VIEW tier so read-only operators still
                // see Lazada in the Integrations hub and contextual sidebar.
                // The dashboard's own derived key (lazada/dashboard): the card
                // decides who sees this channel in the hub and the nav, and
                // that must be the same answer the dashboard's door gives.
                permission: 'view_lazada/dashboard',
                // No card-level menu: unlike Shopee (whose /lazada-equivalent
                // is a storeless OAuth landing) or OpenCart (a storeless
                // Reviews page), Lazada has no channel-global page - every
                // settings route now needs a {store}. The per-store StoreLinks
                // below carry Settings, so a card-level link here would only
                // be a link that throws the moment no store is enabled.
                menu: [],
                stores: $stores,
                addStore: new \App\Integrations\AddStoreAction(
                    route: 'ext.lazada.stores.store',
                    permission: 'manage_lazada/settings',
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
     * @return iterable<\Extensions\lazada\Models\LazadaSetting>
     */
    protected function allStores(): iterable
    {
        try {
            if (! Schema::hasTable('lazada_settings')) {
                return [];
            }

            return LazadaSetting::query()->orderBy('id')->get();
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function orderTabs(): array
    {
        /* Multi-store (Lazada #194 parity with Shopee S4): one tab per
         * enabled store, counters scoped to the store's own rows. With a
         * single store the tab keeps the channel's name - the store names
         * take over the moment a second store exists and the name has to
         * distinguish. */
        if (! Schema::hasTable('lazada_settings')) {
            return [];
        }
        $stores = LazadaSetting::query()->where('enabled', true)->orderBy('id')->get(['id', 'store_name']);

        return $stores->map(fn ($store) => $this->buildOrderTab(
            (int) $store->id,
            \App\Support\StoreLabel::of('Lazada', $store->store_name ?: ('Store #' . $store->id))
        ))->all();
    }

    private function buildOrderTab(int $storeId, string $label): OrderTab
    {
        $lazadaStatusCount = function (array $statuses) use ($storeId): int {
            if (!Schema::hasTable('lazada_orders')) {
                return 0;
            }

            return LazadaOrder::query()->where('lazada_setting_id', $storeId)->whereIn('status', $statuses)->count();
        };

        return
            new OrderTab(
                id: $this->id . ':' . $storeId,
                label: $label,
                icon: 'lazada',
                accent: '#0f146d',
                routeName: 'ext.lazada.orders.index',
                // OrderTab visibility uses view tier so a Lazada Orders Viewer
                // still sees the marketplace's orders tab on the unified Orders
                // workspace. The destination route GETs are read-tier gated,
                // and per-action write buttons are gated separately in the view.
                permission: 'view_lazada/order',
                routeParams: ['store' => $storeId, 'tab' => 'TO_SHIP'],
                unprocessedCounter: function () use ($storeId) {
                    if (!Schema::hasTable('lazada_orders')) {
                        return 0;
                    }
                    return LazadaOrder::query()
                        ->where('lazada_setting_id', $storeId)
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
                        ->whereIn('status', ['pending', 'repacked', 'packed', 'ready_to_ship'])
                        ->count();
                },
                /* The steps while the seller holds the parcel, each counted alone:
                 * the fulfilment card leads with To Pack and prints To Handover beside
                 * it, which the staff count against the shelf. The statuses are the
                 * orders panel's own sub-tab map, so the card and the tab agree. */
                stages: [
                    ['key' => 'to_pack', 'label' => 'to pack', 'counter' => fn () => $lazadaStatusCount(['pending', 'repacked'])],
                    ['key' => 'to_arrange', 'label' => 'to arrange', 'counter' => fn () => $lazadaStatusCount(['packed'])],
                    ['key' => 'to_handover', 'label' => 'to handover', 'counter' => fn () => $lazadaStatusCount(['ready_to_ship'])],
                ],
                dailyOrdersCounter: function () use ($storeId) {
                    if (!Schema::hasTable('lazada_orders')) {
                        return 0;
                    }
                    return LazadaOrder::query()
                        ->where('lazada_setting_id', $storeId)
                        ->where('order_created_at', '>=', now()->startOfDay())
                        ->count();
                },
                dailyRevenueCounter: function () use ($storeId) {
                    if (!Schema::hasTable('lazada_orders') || !Schema::hasTable('lazada_order_products')) {
                        return 0.0;
                    }
                    return (float) DB::table('lazada_order_products as p')
                        ->join('lazada_orders as o', 'o.id', '=', 'p.lazada_order_id')
                        ->where('o.lazada_setting_id', $storeId)
                        ->where('o.order_created_at', '>=', now()->startOfDay())
                        ->sum(DB::raw('p.quantity * COALESCE(p.paid_price, p.item_price)'));
                },
                topProductsCallback: function (int $limit) use ($storeId) {
                    if (!Schema::hasTable('lazada_orders') || !Schema::hasTable('lazada_order_products')) {
                        return [];
                    }
                    return DB::table('lazada_order_products as p')
                        ->join('lazada_orders as o', 'o.id', '=', 'p.lazada_order_id')
                        ->where('o.lazada_setting_id', $storeId)
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
                            DB::raw('SUM(p.quantity * COALESCE(p.paid_price, p.item_price)) as revenue'),
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
                    if (!Schema::hasTable('lazada_orders')) {
                        return [];
                    }
                    $orders = LazadaOrder::query()
                        ->where('lazada_setting_id', $storeId)
                        ->orderByDesc('order_created_at')
                        ->limit($limit)
                        ->get(['id', 'order_id', 'status', 'order_created_at']);

                    $totals = DB::table('lazada_order_products')
                        ->whereIn('lazada_order_id', $orders->pluck('id'))
                        ->select('lazada_order_id', DB::raw('SUM(quantity * COALESCE(paid_price, item_price)) as total'))
                        ->groupBy('lazada_order_id')
                        ->pluck('total', 'lazada_order_id');

                    return $orders->map(fn ($o) => new RecentOrder(
                        reference: (string) $o->order_id,
                        customerName: null,
                        total: (float) ($totals[$o->id] ?? 0),
                        statusLabel: (string) $o->status,
                        orderedAt: $o->order_created_at,
                        url: route('ext.lazada.orders.show', $o->order_id),
                    ))->all();
                },
            );
    }

    /**
     * Translate a Lazada seller_sku into the catalog product mapping.
     * Used by core's OrderStockService when adjusting stock for orders that
     * reference a Lazada-specific SKU (variant maps to a catalog product +
     * optionally an option-value row).
     */
    public function resolveCatalogProduct(string $sku): ?array
    {
        if ($sku === '' || !Schema::hasTable('lazada_product_variants')) {
            return null;
        }

        $variant = LazadaProductVariant::where('seller_sku', $sku)->first();
        if (!$variant) {
            return null;
        }

        $lazadaProduct = LazadaProduct::where('id', $variant->lazada_product_id)->first();
        if (!$lazadaProduct || !$lazadaProduct->product_id) {
            return null;
        }

        return [
            'product_id' => (int) $lazadaProduct->product_id,
            'product_option_value_id' => $variant->product_option_value_id ?: null,
        ];
    }

    /**
     * React to a SKU change on a catalog product by updating the Lazada
     * variant table and pushing the new seller_sku to the Lazada API.
     */
    public function pushSkuChanges(int $productId, array $skuChanges): ?string
    {
        $listing = LazadaProduct::where('product_id', $productId)->first();
        if (!$listing) {
            return null;
        }

        $results = [];

        if (!empty($skuChanges['product_sku'])) {
            $old = $skuChanges['product_sku']['old'];
            $new = $skuChanges['product_sku']['new'];

            $updated = LazadaProductVariant::where('lazada_product_id', $listing->id)
                ->where('seller_sku', $old)
                ->update(['seller_sku' => $new]);

            if ($updated > 0) {
                $results[] = "seller_sku updated ({$old} -> {$new})";
            }

            if (!empty($listing->lazada_item_id)) {
                $apiResult = $this->lazadaUpdateSellerSku($listing, $old, $new);
                if ($apiResult) {
                    $results[] = $apiResult;
                }
            }
        }

        if (!empty($skuChanges['option_skus'])) {
            foreach ($skuChanges['option_skus'] as $povId => $change) {
                $old = $change['old'];
                $new = $change['new'];

                $variant = LazadaProductVariant::where('lazada_product_id', $listing->id)
                    ->where('seller_sku', $old)
                    ->first();

                if ($variant) {
                    $variant->seller_sku = $new;
                    $variant->save();
                    $results[] = "variant SKU ({$old} -> {$new})";
                }
            }
        }

        try {
            $listing->update([
                'last_synced_at' => now(),
                'last_sync_action' => 'sku_sync',
                'last_sync_ok' => true,
            ]);
        } catch (\Throwable) {
            // Non-critical — listing sync metadata is best-effort
        }

        return empty($results) ? null : 'Lazada: ' . implode('; ', $results);
    }

    private function lazadaUpdateSellerSku(LazadaProduct $listing, string $oldSku, string $newSku): ?string
    {
        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$creds['access_token'] || !$creds['app_key']) {
            return null;
        }

        try {
            $client = new LazadaClient();
            $apiPath = '/product/update';

            $itemId = htmlspecialchars((string) $listing->lazada_item_id, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $newSkuEsc = htmlspecialchars($newSku, ENT_XML1 | ENT_QUOTES, 'UTF-8');

            $payloadXml = '<Request><Product>'
                . '<ItemId>' . $itemId . '</ItemId>'
                . '<Skus><Sku>'
                . '<SellerSku>' . $newSkuEsc . '</SellerSku>'
                . '</Sku></Skus>'
                . '</Product></Request>';

            $params = [
                'app_key'      => (string) $creds['app_key'],
                'sign_method'  => 'sha256',
                'timestamp'    => (string) round(microtime(true) * 1000),
                'access_token' => (string) $creds['access_token'],
                'payload'      => $payloadXml,
            ];
            $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);

            $result = $client->post((string) $setting->region, $apiPath, $params);

            LazadaApiLog::safeCreate([
                'pack'            => 'lazada.product.update.sku_sync',
                'method'          => 'POST',
                'api_path'        => $apiPath,
                'auth_required'   => true,
                'request_params'  => $params,
                'response_status' => (int) ($result['status'] ?? 0),
                'ok'              => (bool) ($result['ok'] ?? false),
                'response_body'   => $result['body'] ?? null,
                'user_id'         => auth()->id(),
            ]);

            $body = $result['body'] ?? [];
            $code = is_array($body) ? ($body['code'] ?? null) : null;

            if (($result['ok'] ?? false) && ($code === '0' || $code === 0 || $code === null)) {
                return 'API OK';
            }

            $msg = is_array($body) ? ($body['message'] ?? 'API error') : 'API error';
            Log::info('Lazada SellerSku update response', ['code' => $code, 'message' => $msg]);
            return 'API: ' . $msg;
        } catch (\Throwable $e) {
            Log::warning('Lazada SellerSku update failed', ['error' => $e->getMessage()]);
            return 'API error';
        }
    }

    /**
     * Lazada widgets for the core dashboard: total products, pending orders,
     * and the sync-status row pulled from the settings table.
     */
    public function dashboardData(): array
    {
        $data = [
            'lazadaProducts' => 0,
            'lazadaPending' => 0,
            'lazadaSyncStatus' => null,
        ];

        if (Schema::hasTable('lazada_products')) {
            $data['lazadaProducts'] = (int) DB::table('lazada_products')->count();
        }

        if (Schema::hasTable('lazada_orders')) {
            $data['lazadaPending'] = (int) DB::table('lazada_orders')
                ->whereIn('status', ['pending', 'repacked'])
                ->count();
        }

        if (Schema::hasTable('lazada_settings')) {
            $lzSetting = LazadaSetting::defaultStore();
            if ($lzSetting) {
                /* The listing troubles ride the chip (Phase C, #184), the
                 * same two numbers Shopee's carries. A failure here must
                 * not take the dashboard down. */
                $flags = null;
                $attention = 0;
                try {
                    $sum = app(\Extensions\lazada\Services\Lazada\LazadaListingStates::class)->summary();
                    $attention = (int) $sum['attention'];
                    /* Each figure carries where it goes (#240): the Listings page
                       filtered by the same query that counted it. The words are
                       written once, in App\Integrations\Listings\ListingTrouble,
                       so the chip and the page it lands on say the same thing. */
                    $flags = \App\Integrations\Listings\ListingTrouble::figures($sum, fn (string $bucket) => route('ext.lazada.products.index', ['store' => $lzSetting->id, 'state' => $bucket]));
                } catch (\Throwable) {
                    // the chip simply carries no flags line
                }

                $data['lazadaSyncStatus'] = (object) [
                    'order_sync_at' => $lzSetting->last_order_sync_at,
                    'push_stock_at' => $lzSetting->last_stock_push_at,
                    'return_sync_at' => $lzSetting->last_return_sync_at,
                    'expires_at'    => $lzSetting->expires_at,
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
     * waiting count and its own freshness under its tab id ("lazada:2"),
     * which the dashboard prefers over the channel-level key.
     *
     * @return array{channelPending: array<string, int>, channelSyncStatuses: array<string, object>}
     */
    private function perStoreDashboardFigures(): array
    {
        $pending = [];
        $statuses = [];
        if (! Schema::hasTable('lazada_settings')) {
            return ['channelPending' => $pending, 'channelSyncStatuses' => $statuses];
        }

        foreach (LazadaSetting::query()->orderBy('id')->get() as $store) {
            $key = \App\Support\StoreKey::of('lazada', (int) $store->id);
            if (Schema::hasTable('lazada_orders')) {
                $pending[$key] = (int) DB::table('lazada_orders')
                    ->where('lazada_setting_id', $store->id)
                    ->whereIn('status', ['pending', 'repacked'])
                    ->count();
            }
            /* The listing engine scopes to the bound store, so each store's
             * flags are its own; a failure must not take the strip down. */
            $flags = null;
            $attention = 0;
            $previous = app()->bound('lazada.route-store') ? app('lazada.route-store') : null;
            try {
                app()->instance('lazada.route-store', $store);
                $sum = app(\Extensions\lazada\Services\Lazada\LazadaListingStates::class)->summary();
                $attention = (int) $sum['attention'];
                /* Each figure carries where it goes (#240): the Listings page
                   filtered by the same query that counted it. The words are
                   written once, in App\Integrations\Listings\ListingTrouble,
                   so the chip and the page it lands on say the same thing. */
                $flags = \App\Integrations\Listings\ListingTrouble::figures($sum, fn (string $bucket) => route('ext.lazada.products.index', ['store' => $store->id, 'state' => $bucket]));
            } catch (\Throwable) {
                // this store's chip simply carries no flags line
            } finally {
                if ($previous !== null) {
                    app()->instance('lazada.route-store', $previous);
                } else {
                    app()->forgetInstance('lazada.route-store');
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
     * Lazada catalog orders' product images live in lazada_order_products.
     * Look up the Lazada order by catalog_order_id, then map each product
     * back to its catalog order_product_id via SKU.
     */
    public function imagesForCatalogOrders(array $catalogOrderIds): array
    {
        if (empty($catalogOrderIds) || !Schema::hasTable('lazada_orders')) {
            return [];
        }

        $lazadaOrders = LazadaOrder::whereIn('catalog_order_id', $catalogOrderIds)
            ->with('products')
            ->get()
            ->keyBy('catalog_order_id');

        if ($lazadaOrders->isEmpty()) {
            return [];
        }

        // We need to walk catalog order_products to associate by SKU. The
        // caller (core OrderController) loads orders eager with products,
        // but the contract here only takes catalog_order_ids. Read the
        // catalog order_products table directly.
        $pfx = (string) config('catalog.prefix');
        $opTable = $pfx . 'order_product';
        if (!Schema::hasTable($opTable)) {
            return [];
        }

        $orderProducts = DB::table($opTable)
            ->whereIn('order_id', $catalogOrderIds)
            ->get(['order_product_id', 'order_id', 'model']);

        $result = [];
        foreach ($orderProducts as $op) {
            $lzOrder = $lazadaOrders->get($op->order_id);
            if (!$lzOrder) {
                continue;
            }
            $sku = trim((string) $op->model);
            if ($sku === '') {
                continue;
            }
            $lzProduct = $lzOrder->products->firstWhere('sku', $sku);
            $image = $lzProduct ? trim((string) ($lzProduct->image ?? '')) : '';
            if ($image !== '') {
                $result[(int) $op->order_product_id] = $image;
            }
        }

        return $result;
    }

    /**
     * Lazada fees come from the lazada_orders.fees JSON column. Returns the
     * commission / payment fee / shipping cost / negative other_fees as a
     * structured breakdown.
     */
    /**
     * What Lazada charged for this order, in core's four buckets. Its
     * delivery charge is shipping_service_cost, which sat in the Fees
     * column until 2026-09-08; other_fees is a map and only its NEGATIVE
     * entries are charges - the positives are rebates and were never
     * counted.
     */
    public function saleLinesForOrder(int $coreOrderId, float $itemsSubtotal, float $shipping): ?array
    {
        $order = \Extensions\lazada\Models\LazadaOrder::query()->where('catalog_order_id', $coreOrderId)->first();
        if (! $order) {
            return null;
        }

        return \Extensions\lazada\Services\LazadaSaleLines::lines(is_array($order->raw) ? $order->raw : [], $itemsSubtotal, $shipping);
    }

    public function feeBucketsForOrder(int $coreOrderId): ?array
    {
        if (! Schema::hasTable('lazada_orders')) {
            return null;
        }

        $row = \Extensions\lazada\Models\LazadaOrder::query()->withoutGlobalScope('lazadaStore')
            ->where('catalog_order_id', $coreOrderId)->first();
        if (! $row) {
            return null;
        }

        $payload = \App\Integrations\Orders\FeePayload::decode($row->fees);
        if (\App\Integrations\Orders\FeePayload::isEmpty($payload)) {
            return null;
        }

        $other = 0.0;
        foreach ((array) ($payload['other_fees'] ?? []) as $amount) {
            if ((float) $amount < 0) {
                $other += abs((float) $amount);
            }
        }

        return [
            'commission' => \App\Integrations\Orders\FeePayload::amount($payload, 'commission'),
            'payment' => \App\Integrations\Orders\FeePayload::amount($payload, 'payment_fee'),
            'shipping' => \App\Integrations\Orders\FeePayload::amount($payload, 'shipping_service_cost'),
            'other' => $other,
        ];
    }

    public function renderOrderRef(string $marketplaceSource, string $marketplaceOrderId): ?array
    {
        if ($marketplaceSource !== 'lazada') {
            return null;
        }

        return ['display' => $marketplaceOrderId, 'url' => 'https://sellercenter.lazada.com.ph/order/detail?tradeOrderId=' . rawurlencode($marketplaceOrderId)];
    }

    public function feesForOrder(Order $order): ?array
    {
        if ((string) $order->marketplace_source !== 'lazada') {
            return null;
        }

        $mktOrderId = (string) $order->marketplace_order_id;
        if ($mktOrderId === '' || !Schema::hasTable('lazada_orders')) {
            return null;
        }

        $lzOrder = LazadaOrder::where('order_id', $mktOrderId)->first();
        if (!$lzOrder || empty($lzOrder->fees)) {
            return ['total' => 0, 'items' => [], 'source' => 'lazada'];
        }

        $fees = is_array($lzOrder->fees) ? $lzOrder->fees : [];
        $items = [];
        $total = 0;

        $commission = abs((float) ($fees['commission'] ?? 0));
        $paymentFee = abs((float) ($fees['payment_fee'] ?? 0));
        $shippingCost = abs((float) ($fees['shipping_service_cost'] ?? 0));

        if ($commission > 0)   { $items[] = ['label' => 'Commission',    'amount' => $commission];  $total += $commission; }
        if ($paymentFee > 0)   { $items[] = ['label' => 'Payment Fee',   'amount' => $paymentFee];  $total += $paymentFee; }
        if ($shippingCost > 0) { $items[] = ['label' => 'Shipping Fee',  'amount' => $shippingCost]; $total += $shippingCost; }

        $otherFees = $fees['other_fees'] ?? [];
        if (is_array($otherFees)) {
            foreach ($otherFees as $label => $amount) {
                if ((float) $amount < 0) {
                    $amt = abs((float) $amount);
                    $items[] = ['label' => ucwords(str_replace('_', ' ', $label)), 'amount' => $amt];
                    $total += $amt;
                }
            }
        }

        return ['total' => round($total, 2), 'items' => $items, 'source' => 'lazada'];
    }

    // ── Mobile API ─────────────────────────────────────────────

    public function mobilePlatformSlug(): string
    {
        return 'lazada';
    }

    public function mobileIndexResponse(Request $request): array
    {
        $tab = strtoupper((string) $request->query('tab', 'ALL'));
        $search = trim((string) $request->query('q', ''));
        $pendingSub = (string) $request->query('pending_sub', '');
        if ($tab !== 'PENDING' || !array_key_exists($pendingSub, self::MOBILE_PENDING_SUB_MAP)) {
            $pendingSub = '';
        }

        $region = LazadaSetting::defaultStore()->region ?? 'ph';
        $base = LazadaOrder::query()->where('region', $region);
        $query = LazadaOrder::query()->where('region', $region);

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
                $q->where('order_id', 'like', $like)
                    ->orWhere('raw->customer_first_name', 'like', $like)
                    ->orWhere('raw->customer_name', 'like', $like)
                    ->orWhere('raw->buyer_name', 'like', $like);
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
                'buyer_name'      => $raw['customer_first_name'] ?? $raw['customer_name'] ?? $raw['buyer_name'] ?? '',
                'total_amount'    => isset($raw['price']) ? (float) $raw['price'] : null,
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
        $order = LazadaOrder::with('products')->find($id);
        if (!$order) {
            return null;
        }

        $raw = $order->raw ?? [];
        $detail = $raw['_detail'] ?? $raw;
        $address = $detail['address_shipping'] ?? [];

        $products = $order->products
            ->groupBy(fn ($p) => ($p->sku ?? '') . '|' . ($p->variation ?? ''))
            ->map(function ($group) {
                $first = $group->first();
                $pRaw = $first->raw ?? [];
                return [
                    'name'      => $first->name,
                    'sku'       => $first->sku ?? '',
                    'variation' => $first->variation ?? '',
                    'quantity'  => $group->sum('quantity'),
                    'price'     => isset($pRaw['item_price']) ? (float) $pRaw['item_price'] : 0,
                    'image'     => $first->image ?? $pRaw['product_main_image'] ?? '',
                ];
            });

        return [
            'order_id'          => (string) $order->order_id,
            'status'            => $order->status,
            'buyer_name'        => $detail['customer_first_name'] ?? $detail['customer_name'] ?? $detail['buyer_name'] ?? '',
            'total_amount'      => isset($detail['price']) ? (float) $detail['price'] : null,
            'currency'          => $detail['currency'] ?? \App\Support\Money::defaultSymbol(),
            'payment_method'    => $detail['payment_method'] ?? '',
            'shipping_provider' => $detail['shipping_provider'] ?? '',
            'tracking_number'   => $detail['tracking_code'] ?? $detail['tracking_number'] ?? '',
            'shipping_name'     => trim(($address['first_name'] ?? '') . ' ' . ($address['last_name'] ?? '')),
            'shipping_phone'    => $address['phone'] ?? $address['phone2'] ?? '',
            'shipping_address'  => trim(implode(', ', array_filter([
                $address['address1'] ?? '',
                $address['address2'] ?? '',
                $address['address3'] ?? '',
                $address['city'] ?? '',
                $address['post_code'] ?? '',
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
        return $source === 'lazada' ? 'Lazada' : null;
    }

    /**
     * Per-store reports (the owner, 2026-09-05): every store as a filter
     * choice, paused ones included. A deleted store is simply gone.
     */
    public function availableStoreOptions(): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('lazada_settings')) {
            return [];
        }

        return \Extensions\lazada\Models\LazadaSetting::query()->orderBy('id')->get(['id', 'store_name'])->map(fn ($s) => [
            'key'        => \App\Support\StoreKey::of('lazada', (int) $s->id),
            'label'      => $s->store_name ?: ('Store #' . $s->id),
            'channel'    => 'Lazada',
            'source'     => 'lazada',
            'store_id'   => (int) $s->id,
            'permission' => 'view_lazada/order',
        ])->all();
    }

    public function availableSourceOptions(): array
    {
        return [[
            'value'       => 'lazada',
            'label'       => 'Lazada',
            'channel'     => 'Lazada',
            'badge_class' => 'badge-indigo',
            'chart_color' => '#0F146D',
        ]];
    }

    // ── Credential reveal ──────────────────────────────────────

    /**
     * The two secrets an operator has a real reason to read back, and nothing
     * else. App KEYS are not here: they are identifiers, they already render
     * in the clear, and adding them would widen the allowlist for no gain.
     *
     * @return array<string, string>
     */
    use RevealsCredentials;

    protected function credentialSettingsModel(): string
    {
        return \Extensions\lazada\Models\LazadaSetting::class;
    }

    public function revealableCredentials(): array
    {
        return [
            'app_secret'           => 'App Secret',
            'access_token'         => 'Access token',
            'sandbox_app_secret'   => 'Sandbox App Secret',
            'sandbox_access_token' => 'Sandbox access token',
        ];
    }

    public function credentialManagePermission(): string
    {
        return 'manage_lazada/settings';
    }

    /**
     * Phase C (#184): the one answer to "where does this product stand on
     * Lazada?", for core surfaces that render listing state.
     */
    public function listingStates(array $productIds): array
    {
        return app(\Extensions\lazada\Services\Lazada\LazadaListingStates::class)->forProducts($productIds);
    }


    /** Every catalog product Lazada currently lists (#197). */
    public function listedProductIds(): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('lazada_products')) {
            return [];
        }

        return \Extensions\lazada\Models\LazadaProduct::query()
            ->whereNotNull('lazada_item_id')->whereNull('lazada_deleted_at')
            ->distinct()->pluck('product_id')->map(fn ($v) => (int) $v)->all();
    }

    public function listingUrls(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        // The listing page is keyed by the ROW, so resolve each product's
        // preferred row (the linked one, else the newest) in one query.
        $rows = LazadaProduct::query()
            ->whereIn('product_id', $productIds ?: [0])
            ->orderByRaw("(lazada_item_id IS NOT NULL AND lazada_item_id <> '' AND unlinked_at IS NULL) asc")
            ->orderBy('id')
            ->pluck('id', 'product_id'); // later rows overwrite earlier: preferred wins

        $out = [];
        foreach ($productIds as $pid) {
            // The product id is the key (2026-09-07): one page whether a row exists yet or not.
            $out[$pid] = route('ext.lazada.products.edit', $pid);
        }

        return $out;
    }

    // ── A catalog delete forgets the product on this channel, inside VentaSync only (ProductRemover, 2026-09-07) ──

    public function productPresence(array $productIds): array
    {
        return app(\Extensions\lazada\Services\Lazada\LazadaProductRemover::class)->presence($productIds);
    }

    public function removeProduct(int $productId): array
    {
        return app(\Extensions\lazada\Services\Lazada\LazadaProductRemover::class)->remove($productId);
    }

    public function strandedProductIds(): array
    {
        return app(\Extensions\lazada\Services\Lazada\LazadaProductRemover::class)->stranded();
    }
}
