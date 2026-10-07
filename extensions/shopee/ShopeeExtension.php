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

class ShopeeExtension extends ExtensionProvider implements ListingStateSource, IntegrationProvider, SkuSyncContributor, DashboardContributor, OrderFeesContributor, \App\Integrations\Contracts\MarketplaceFeeSource, MobileMarketplaceProvider, \App\Integrations\Contracts\MobileFulfilmentProvider, LayoutBannerContributor, MarketplaceSourceOptionsProvider, RootRouteHandler, CredentialRevealer, \App\Integrations\Contracts\WebhookReceiver, \App\Integrations\Contracts\StoreOptionsProvider, \App\Integrations\Contracts\ProductRemover, \App\Integrations\Contracts\VariationForgetter, \App\Integrations\Contracts\MarketplaceOrderRefRenderer, \App\Integrations\Contracts\SaleLinesSource
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

    private const ORDER_PUSH_TYPES = ['order_status', 'order_tracking', 'return_update'];

    public function boot(): void
    {
        parent::boot();

        \Extensions\shopee\Models\ShopeeSetting::syncUrlDefault();

        $this->app->scoped(\Extensions\shopee\Services\Shopee\ShopeeLinkCheck::class);

        \App\Models\ChannelWebhookEvent::created(function (\App\Models\ChannelWebhookEvent $event) {
            if ($event->channel === 'shopee' && $event->signature_ok && in_array($event->event_type, self::ORDER_PUSH_TYPES, true)
                && $this->webhookStore((array) $event->payload)?->apply_order_pushes) {
                $this->app->terminating(fn () => $this->applyPushNow((int) $event->id));
            }
        });

        $this->commands([
            \Extensions\shopee\Commands\ShopeePushStock::class,
            \Extensions\shopee\Commands\ShopeePushPrice::class,
            \Extensions\shopee\Commands\ShopeeFindDuplicates::class,
            \Extensions\shopee\Commands\ShopeeRefreshListingStatus::class,
            \Extensions\shopee\Commands\ShopeeRefreshToken::class,
            \Extensions\shopee\Commands\ShopeeSyncOrders::class,
            \Extensions\shopee\Commands\ShopeeSyncPayouts::class,
            \Extensions\shopee\Commands\ShopeeSyncReviews::class,
        ]);

        $this->app->make(IntegrationRegistry::class)->register($this);

        $this->app->make(\App\Services\Payouts\PayoutRegistry::class)->register(new \App\Services\Payouts\PayoutChannel(
            id: 'shopee',
            name: 'Shopee',
            accent: '#EE4D2D',
            attention: \Extensions\shopee\Services\Shopee\ShopeePayoutSync::attention(),
            stores: fn () => \Extensions\shopee\Models\ShopeeSetting::query()->where('enabled', true)->orderBy('id')->get(),
            readBalance: fn (object $store) => app(\Extensions\shopee\Services\Shopee\ShopeeBalance::class)->read($store),
            pageRoute: 'ext.shopee.dashboard',
        ));
    }

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
                permission: 'view_shopee/order',
                unprocessedCounter: function () use ($storeId) {
                    if (!Schema::hasTable('shopee_orders')) {
                        return 0;
                    }
                    return ShopeeOrder::query()
                        ->where('shopee_setting_id', $storeId)
                        ->whereIn('status', ['READY_TO_SHIP', 'PROCESSED'])
                        ->count();
                },
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

    public function dashboardData(): array
    {
        $data = [
            'shopeePending' => 0,
            'shopeeSyncStatus' => null,
        ];

        if (Schema::hasTable('shopee_orders')) {
            $data['shopeePending'] = (int) DB::table('shopee_orders')
                ->whereIn('status', ['READY_TO_SHIP', 'PROCESSED'])
                ->count();
        }

        if (Schema::hasTable('shopee_settings')) {
            $spSetting = ShopeeSetting::defaultStore();
            if ($spSetting) {
                $flags = null;
                $attention = 0;
                try {
                    $sum = app(\Extensions\shopee\Services\Shopee\ShopeeListingStates::class)->summary();
                    $attention = (int) $sum['attention'];
                    $flags = \App\Integrations\Listings\ListingTrouble::figures($sum, fn (string $bucket) => route('ext.shopee.products.index', ['store' => $spSetting->id, 'state' => $bucket]));
                } catch (\Throwable) {
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


        $data += $this->perStoreDashboardFigures();

        return $data;
    }

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
                    ->whereIn('status', ['READY_TO_SHIP', 'PROCESSED'])
                    ->count();
            }
            $flags = null;
            $attention = 0;
            $previous = app()->bound('shopee.route-store') ? app('shopee.route-store') : null;
            try {
                app()->instance('shopee.route-store', $store);
                $sum = app(\Extensions\shopee\Services\Shopee\ShopeeListingStates::class)->summary();
                $attention = (int) $sum['attention'];
                $flags = \App\Integrations\Listings\ListingTrouble::figures($sum, fn (string $bucket) => route('ext.shopee.products.index', ['store' => $store->id, 'state' => $bucket]));
            } catch (\Throwable) {
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

        $storeId = $request->integer('store') ?: null;
        if ($storeId !== null) {
            $base = ShopeeOrder::query()->where('shopee_setting_id', $storeId);
            $query = ShopeeOrder::query()->where('shopee_setting_id', $storeId);
        } else {
            $region = ShopeeSetting::defaultStore()->region ?? 'ph';
            $base = ShopeeOrder::query()->where('region', $region);
            $query = ShopeeOrder::query()->where('region', $region);
        }
        $storeNames = ShopeeSetting::query()->pluck('store_name', 'id');

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

        $data = $orders->map(function ($order) use ($storeNames) {
            $raw = $order->raw ?? [];
            $firstProduct = $order->products()->orderBy('id')->first();
            $productRaw = $firstProduct?->raw ?? [];
            $productImage = $firstProduct?->image
                ?? ($productRaw['image_info']['image_url'] ?? null)
                ?? ($productRaw['product_main_image'] ?? null);

            return [
                'id'              => $order->id,
                'store_id'        => (int) $order->shopee_setting_id,
                'store_name'      => (string) ($storeNames[$order->shopee_setting_id] ?? ''),
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
                'fulfilment'      => \Extensions\shopee\Services\ShopeeAppFulfilment::block($order),
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
            'id'                => $order->id,
            'order_id'          => $order->order_sn,
            'store_id'          => (int) $order->shopee_setting_id,
            'store_name'        => (string) ShopeeSetting::query()->whereKey($order->shopee_setting_id)->value('store_name'),
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
            'fulfilment'        => \Extensions\shopee\Services\ShopeeAppFulfilment::block($order),
        ];
    }

    public function mobileFulfilmentActions(): array
    {
        return \Extensions\shopee\Services\ShopeeAppFulfilment::ACTIONS;
    }

    public function mobileFulfilmentAction(string $action, int $id, Request $request): \Symfony\Component\HttpFoundation\Response
    {
        return app(\Extensions\shopee\Services\ShopeeAppFulfilment::class)->handle($action, $id, $request);
    }

    public function layoutBanners(): array
    {
        $banners = [];

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
                'label'    => 'Shopee' . ($pausedLabel ? " ({$pausedLabel})" : '') . ': Order sync paused. ' . $paused,
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

    public function handleRootRoute(Request $request): mixed
    {
        if (!$request->query('code') && !$request->query('shop_id')) {
            return null;
        }

        return app(\Extensions\shopee\Controllers\ShopeeController::class)
            ->root($request, app(ShopeeClient::class));
    }

    use RevealsCredentials;

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
            'sandbox_push_partner_key' => 'Sandbox push partner key',
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

    public function listingStates(array $productIds): array
    {
        return app(\Extensions\shopee\Services\Shopee\ShopeeListingStates::class)->forProducts($productIds);
    }


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

    public function webhookChannel(): string
    {
        return 'shopee';
    }

    // Shopee signs pushes with HMAC-SHA256(key, url|body); try bare, full and https forms of the URL.
    public function verifyWebhook(\Illuminate\Http\Request $request): bool
    {
        // Try the payload store's keys first, then every other store's, in both environments; no match is refused.
        $received = (string) $request->header('Authorization', '');
        if ($received === '') {
            return false;
        }

        $keys = [];
        $named = $this->webhookStore((array) json_decode((string) $request->getContent(), true));
        $stores = ShopeeSetting::query()->where('enabled', true)->orderBy('id')->get();
        foreach (($named ? collect([$named])->merge($stores) : $stores) as $store) {
            $setting = $store->decrypted();
            $auth = ShopeeSetting::activeAuth($setting);
            foreach ([$setting->push_partner_key ?? null, $setting->sandbox_push_partner_key ?? null, $auth['partner_key'] ?? null] as $key) {
                if (is_string($key) && $key !== '') {
                    $keys[$key] = true;
                }
            }
        }

        $urls = [];
        foreach ([$request->url(), $request->fullUrl()] as $url) {
            $urls[$url] = true;
            $urls[preg_replace('/^http:/i', 'https:', $url)] = true;
        }

        foreach (array_keys($keys) as $key) {
            foreach (array_keys($urls) as $url) {
                if (hash_equals(hash_hmac('sha256', $url . '|' . $request->getContent(), $key), $received)) {
                    return true;
                }
            }
        }

        return false;
    }

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

    public function webhookEventType(array $payload): ?string
    {
        $data = (array) ($payload['data'] ?? []);
        if (isset($data['item_id'], $data['item_status'])) {
            return 'item_status';
        }
        $code = isset($payload['code']) ? (int) $payload['code'] : null;
        if (! $this->webhookStore($payload)?->apply_order_pushes) {
            if (isset($data['ordersn'])) {
                return 'order_status';
            }

            return $code !== null ? 'code_' . $code : null;
        }
        if ($code === 4 && isset($data['ordersn'])) {
            return 'order_tracking';
        }
        if ($code === 29) {
            return 'return_update';
        }
        if (isset($data['ordersn'])) {
            return 'order_status';
        }

        return $code !== null ? 'code_' . $code : null;
    }

    public function handleWebhookEvent(\App\Models\ChannelWebhookEvent $event): string
    {
        $data = (array) ($event->payload['data'] ?? []);

        if ($event->event_type === 'item_status') {
            $itemId = (int) $data['item_id'];
            $status = strtoupper(trim((string) $data['item_status']));
            if ($status === '') {
                return 'item push without a status; nothing applied';
            }
            $store = $this->webhookStore((array) $event->payload);
            $n = ShopeeProductLink::query()
                ->when($store !== null, fn ($q) => $q->where('shopee_setting_id', $store->id))
                ->where('shopee_item_id', $itemId)
                ->update(['live_status' => $status, 'live_checked_at' => now()]);

            return $n > 0
                ? "item {$itemId} mirror -> {$status} ({$n} link row" . ($n === 1 ? '' : 's') . ')'
                : "item {$itemId} is not linked here; nothing to refresh";
        }

        if (in_array($event->event_type, self::ORDER_PUSH_TYPES, true)) {
            return $this->applyOrderPush($event, $data);
        }

        return 'stored; no handler for ' . ($event->event_type ?? 'this shape') . ' yet';
    }

    private function applyOrderPush(\App\Models\ChannelWebhookEvent $event, array $data): string
    {
        $isReturn = $event->event_type === 'return_update';
        $sn = (string) ($data['ordersn'] ?? '');
        $returnSn = (string) ($data['return_sn'] ?? '');
        $what = $isReturn ? 'return ' . ($returnSn !== '' ? $returnSn : '(no return_sn)') : 'order ' . $sn;
        $status = (string) ($data['status'] ?? '');
        $said = $event->event_type === 'order_tracking' ? 'tracking number' : ($status !== '' ? $status : 'an update');

        $store = $this->webhookStore((array) $event->payload);
        if (! $store) {
            return "order {$sn} moved to {$status} on Shopee; the next order sync applies it (stock rides that path)";
        }
        if (! $store->apply_order_pushes) {
            return "order {$sn} moved to {$status} on Shopee; the next order sync applies it (stock rides that path)";
        }
        if ($isReturn && $returnSn === '') {
            return "{$what}: return push without a return number; the next return sync applies it";
        }
        if (! $isReturn && $sn === '') {
            return 'order push without an order number; nothing applied';
        }

        $setting = $store->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (! $auth['complete']) {
            return "{$what}: the store is not connected to Shopee; the next sync applies it";
        }
        if ($paused = Cache::get('shopee_sync_paused:' . $store->id)) {
            return "{$what}: the store is paused after a Shopee error ({$paused}); the next sync applies it";
        }

        $ingest = app(\Extensions\shopee\Services\Shopee\ShopeeOrderIngest::class);

        if ($isReturn) {
            $error = $ingest->returnBySn($setting, $auth, $returnSn);

            return $error === null ? "{$what} read from Shopee and saved" : "failed: {$error}";
        }

        $r = $ingest->orders($setting, $auth, [$sn]);
        if ($r['created'] > 0) {
            return "{$what} ({$said}) brought in from Shopee";
        }
        if ($r['updated'] > 0) {
            return "{$what} ({$said}) updated from Shopee";
        }
        if ($r['busy'] > 0) {
            return "{$what} ({$said}) was being written by another sync; left to it";
        }
        if ($r['missing'] !== []) {
            return "failed: Shopee did not return {$what}";
        }

        return "failed: Shopee did not answer for {$what}";
    }

    private function applyPushNow(int $eventId): void
    {
        try {
            $event = \App\Models\ChannelWebhookEvent::query()->find($eventId);
            if (! $event || $event->processed_at !== null) {
                return;
            }
            $store = $this->webhookStore((array) $event->payload);
            if (! $store || ! $store->apply_order_pushes) {
                return;
            }

            $outcome = $this->handleWebhookEvent($event);
            if (str_starts_with($outcome, 'failed:')) {
                return;
            }

            \App\Models\ChannelWebhookEvent::query()
                ->whereKey($eventId)
                ->whereNull('processed_at')
                ->update(['processed_at' => now(), 'outcome' => mb_substr($outcome . ' (on arrival)', 0, 255)]);
        } catch (\Throwable $e) {
            Log::warning('Shopee push could not be applied on arrival; webhooks:process will', ['event' => $eventId, 'error' => $e->getMessage()]);
        }
    }

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

    public function automations(): array
    {
        return [
            ['shopee:sync-orders', 'Sync Orders', 15, 'minute', ['no_returns' => true]],
            ['shopee:sync-orders', 'Sync Returns', 30, 'minute', ['returns' => true]],
            ['shopee:sync-payouts', 'Sync Payouts', 1, 'hour', ['days' => \App\Support\PayoutStatus::DEFAULT_LOOKBACK_DAYS]],
            ['shopee:push-stock', 'Push Stock', 30, 'minute'],
            ['shopee:push-price', 'Push Price', 30, 'minute'],
            ['shopee:sync-reviews', 'Sync Reviews', 1, 'hour', ['days' => 7]],
            ['shopee:refresh-token', 'Refresh Access Token', 2, 'hour'],
            ['shopee:refresh-listing-status', 'Refresh Listing Status', 30, 'minute'],
        ];
    }
}
