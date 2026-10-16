<?php

namespace Extensions\VentaCart;

use App\Extensions\ExtensionProvider;
use App\Channels\Contracts\DashboardContributor;
use App\Channels\Contracts\ListingStateSource;
use App\Channels\Contracts\ChannelOptionsProvider;
use App\Channels\Contracts\ProductActionContributor;
use App\Channels\Contracts\SkuSyncContributor;
use App\Channels\Dto\RecentOrder;
use App\Channels\Dto\TopProduct;
use App\Channels\ChannelCard;
use App\Channels\ChannelProvider;
use App\Channels\ChannelRegistry;
use App\Channels\MenuItem;
use App\Channels\OrderTab;
use App\Channels\StoreLink;
use App\Models\Catalog\OrderStatus;
use App\Support\FulfillmentSteps;
use Extensions\VentaCart\Models\VentaCartOrder;
use Extensions\VentaCart\Models\VentaCartListingLink;
use Extensions\VentaCart\Models\VentaCartStore;
use Extensions\VentaCart\Services\VentaCart\VentaCartClient;
use Extensions\VentaCart\Services\VentaCart\VentaCartStatusPlacements;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

use App\Channels\Contracts\CredentialRevealer;
use App\Channels\Concerns\RevealsCredentials;

class VentaCartExtension extends ExtensionProvider implements \App\Channels\Contracts\ChannelFeeSource, ChannelProvider, SkuSyncContributor, DashboardContributor, ChannelOptionsProvider, CredentialRevealer, ProductActionContributor, \App\Channels\Contracts\StoreOptionsProvider, \App\Channels\Contracts\ProductRemover, ListingStateSource, \App\Channels\Contracts\SaleLinesSource
{
    protected string $id = 'ventacart';

    public function boot(): void
    {
        parent::boot();

        $this->commands([
            \Extensions\VentaCart\Commands\VentaCartSync::class,
            \Extensions\VentaCart\Commands\VentaCartPushStock::class,
            \Extensions\VentaCart\Commands\VentaCartPushPrice::class,
            \Extensions\VentaCart\Commands\VentaCartPushReviews::class,
            \Extensions\VentaCart\Commands\VentaCartRefreshListingStatus::class,
            \Extensions\VentaCart\Commands\VentaCartFeeKeys::class,
            \Extensions\VentaCart\Commands\VentaCartRefreshOrderFees::class,
        ]);

        $this->app->make(ChannelRegistry::class)->register($this);
    }


    public function channelCards(): array
    {
        $stores = [];
        foreach ($this->allStores() as $store) {
            $stores[] = new StoreLink(
                id: $this->id . ':' . $store->id,
                label: $store->store_name,
                menu: [
                    new MenuItem('Settings', 'ext.ventacart.settings.show', 'view_ventacart/settings', null, ['store' => $store->id], 'Channel', children: [
                        ['label' => 'Connection', 'section' => 'connection'],
                        ['label' => 'Status mapping', 'section' => 'status'],
                        ['label' => 'Automations', 'section' => 'automations'],
                        ['label' => 'API log', 'section' => 'logs'],
                        ['label' => 'Sync log', 'section' => 'sync'],
                    ]),
                    new MenuItem('Products', 'ext.ventacart.listings.index', 'view_ventacart/listing', null, ['store' => $store->id], 'Catalog'),
                    new MenuItem('Product Groups', 'ext.ventacart.product-groups.index', 'view_ventacart/product_group', null, ['store' => $store->id], 'Catalog'),
                    new MenuItem('Description Templates', 'ext.ventacart.description-templates.index', 'view_ventacart/description_template', null, ['store' => $store->id], 'Catalog'),
                    new MenuItem('Watermarks', 'ext.ventacart.watermarks.index', 'view_ventacart/watermark_template', null, ['store' => $store->id], 'Catalog'),
                    new MenuItem('Import', 'ext.ventacart.products.import', 'view_ventacart/listing', null, ['store' => $store->id], 'Catalog'),
                    new MenuItem('Orders', 'ext.ventacart.orders.index', 'view_ventacart/order', null, ['store' => $store->id], 'Sell'),
                ],
                status: $store->enabled ? 'active' : 'setup_pending',
            );
        }

        return [
            new ChannelCard(
                id: $this->id,
                name: 'VentaCart',
                tagline: 'Multi-store ecommerce. Add stores, sync orders, push inventory.',
                icon: 'shop',
                accent: '#7c3aed',
                permission: 'view_ventacart/dashboard',
                menu: [],
                stores: $stores,
                addStore: new \App\Channels\AddStoreAction(
                    route: 'ext.ventacart.stores.store',
                    permission: 'manage_ventacart/settings',
                    label: 'Add store',
                    fields: [
                        ['name' => 'base_url', 'label' => 'Store URL', 'type' => 'url', 'placeholder' => 'https://yourstore.venta.ph', 'hint' => 'The address of your VentaCart storefront.'],
                    ],
                ),
            ),
        ];
    }

    public function orderTabs(): array
    {
        $tabs = [];
        foreach ($this->enabledStores() as $store) {
            $tabs[] = $this->buildOrderTab((int) $store->id, \App\Support\StoreLabel::of('VentaCart', $store->store_name));
        }
        return $tabs;
    }

    public function listingStates(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === [] || ! Schema::hasTable('ch_ventacart_listings')) {
            return [];
        }
        $out = [];
        foreach ($this->enabledStores() as $store) {
            $here = array_values(array_intersect($productIds, \Extensions\VentaCart\Services\VentaCart\VentaCartStoreProducts::ids((int) $store->id)));
            $here = array_values(array_diff($here, array_keys($out)));
            if ($here === []) {
                continue;
            }
            foreach (\Extensions\VentaCart\Services\VentaCart\VentaCartListingStates::for((int) $store->id)->forProducts($here) as $pid => $state) {
                $out[$pid] = $state;
            }
        }

        return $out;
    }

    public function listingUrls(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        $out = array_fill_keys($productIds, null);
        if ($productIds === [] || ! Schema::hasTable('ch_ventacart_listings')) {
            return $out;
        }
        $first = null;
        foreach ($this->enabledStores() as $store) {
            $first ??= (int) $store->id;
            foreach (array_intersect($productIds, \Extensions\VentaCart\Services\VentaCart\VentaCartStoreProducts::ids((int) $store->id)) as $pid) {
                $out[$pid] ??= route('ext.ventacart.listings.edit', [$store->id, $pid]);
            }
        }
        if ($first !== null) {
            foreach ($out as $pid => $url) {
                $out[$pid] = $url ?? route('ext.ventacart.listings.index', $first);
            }
        }

        return $out;
    }

    public function listedProductIds(): array
    {
        if (! Schema::hasTable('ch_ventacart_listing_links')) {
            return [];
        }

        return VentaCartListingLink::query()->whereNotNull('ventacart_product_id')
            ->distinct()->pluck('product_id')->map(fn ($v) => (int) $v)->all();
    }

    protected function enabledStores(): iterable
    {
        if (!Schema::hasTable('ch_ventacart_stores')) {
            return [];
        }
        return VentaCartStore::where('enabled', true)->orderBy('id')->get();
    }

    protected function allStores(): iterable
    {
        if (!Schema::hasTable('ch_ventacart_stores')) {
            return [];
        }
        return VentaCartStore::orderBy('id')->get();
    }

    public static function stepCounts(int $storeId): array
    {
        if (!Schema::hasTable('ch_ventacart_orders')) {
            return array_fill_keys(FulfillmentSteps::placements(), 0);
        }

        $statusCounts = VentaCartOrder::query()
            ->where('store_id', $storeId)
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        return array_map(
            fn (array $bucket) => $bucket['count'],
            FulfillmentSteps::bucket('ventacart.orders', $statusCounts, VentaCartStatusPlacements::resolver($storeId))
        );
    }

    protected function buildOrderTab(int $storeId, string $storeName): OrderTab
    {
        $orderChannel = 'ventacart:' . $storeId;
        $orderTable = 'orders';
        $productTable = 'order_products';

        return new OrderTab(
            id: $this->id . ':' . $storeId,
            label: $storeName,
            icon: 'shop',
            accent: '#7c3aed',
            routeName: 'ext.ventacart.orders.index',
            permission: 'view_ventacart/order',
            routeParams: ['store' => $storeId],
            unprocessedCounter: function () use ($storeId) {
                $steps = self::stepCounts($storeId);

                return $steps['to_pack'] + $steps['to_handover'];
            },
            stages: [
                ['key' => 'to_pack', 'label' => 'to pack', 'counter' => fn () => self::stepCounts($storeId)['to_pack']],
                ['key' => 'to_handover', 'label' => 'to handover', 'counter' => fn () => self::stepCounts($storeId)['to_handover']],
            ],
            dailyOrdersCounter: function () use ($orderChannel, $orderTable) {
                if (!Schema::hasTable($orderTable)) {
                    return 0;
                }
                return DB::table($orderTable)
                    ->where('channel', $orderChannel)
                    ->where('date_added', '>=', now()->startOfDay())
                    ->count();
            },
            dailyRevenueCounter: function () use ($orderChannel, $orderTable) {
                if (!Schema::hasTable($orderTable)) {
                    return 0.0;
                }
                return (float) DB::table($orderTable)
                    ->where('channel', $orderChannel)
                    ->where('date_added', '>=', now()->startOfDay())
                    ->sum('total');
            },
            topProductsCallback: function (int $limit) use ($orderChannel, $orderTable, $productTable) {
                if (!Schema::hasTable($orderTable) || !Schema::hasTable($productTable)) {
                    return [];
                }
                $catalogProductTable = 'products';
                return DB::table($productTable . ' as p')
                    ->join($orderTable . ' as o', 'o.order_id', '=', 'p.order_id')
                    ->leftJoin($catalogProductTable . ' as cp', 'cp.product_id', '=', 'p.product_id')
                    ->where('o.channel', $orderChannel)
                    ->where('o.date_added', '>=', now()->startOfDay())
                    ->whereNotNull('p.sku')
                    ->where('p.sku', '!=', '')
                    ->leftJoin(DB::raw('(SELECT order_product_id, GROUP_CONCAT(CONCAT(name, \': \', value) ORDER BY order_variation_id SEPARATOR \', \') AS variation FROM `order_variations` GROUP BY order_product_id) AS oo'), 'oo.order_product_id', '=', 'p.order_product_id')
                    ->groupBy('p.sku', 'oo.variation')
                    ->orderByDesc('qty_sold')->orderByDesc('revenue')->orderBy('name')
                    ->limit($limit)
                    ->select(
                        'p.sku',
                        DB::raw('MAX(p.name) as name'),
                        DB::raw('MAX(cp.image) as image'),
                        DB::raw('oo.variation as variation'),
                        DB::raw('SUM(p.quantity) as qty_sold'),
                        DB::raw('SUM(p.total) as revenue'),
                    )
                    ->get()
                    ->map(fn ($r) => new TopProduct(
                        sku: (string) $r->sku,
                        name: (string) ($r->name ?? $r->sku),
                        imageUrl: $r->image ? \App\Services\Media\ImageCache::url($r->image) : null,
                        qtySold: (int) $r->qty_sold,
                        revenue: (float) $r->revenue,
                        variation: trim((string) ($r->variation ?? '')) ?: null,
                    ))->all();
            },
            recentOrdersCallback: function (int $limit) use ($orderChannel, $orderTable) {
                if (!Schema::hasTable($orderTable)) {
                    return [];
                }
                $statuses = Schema::hasTable('order_statuses')
                    ? OrderStatus::query()->pluck('name', 'order_status_id')->all()
                    : [];

                return DB::table($orderTable)
                    ->where('channel', $orderChannel)
                    ->orderByDesc('date_added')
                    ->limit($limit)
                    ->select('order_id', 'firstname', 'lastname', 'total', 'order_status_id', 'date_added')
                    ->get()
                    ->map(fn ($o) => new RecentOrder(
                        reference: '#' . $o->order_id,
                        customerName: trim(($o->firstname ?? '') . ' ' . ($o->lastname ?? '')) ?: null,
                        total: (float) $o->total,
                        statusLabel: (string) ($statuses[$o->order_status_id] ?? '-'),
                        orderedAt: $o->date_added ? new \DateTimeImmutable((string) $o->date_added) : null,
                        url: route('orders.show', $o->order_id),
                    ))->all();
            },
        );
    }

    public function pushSkuChanges(int $productId, array $skuChanges): ?string
    {
        if (empty($skuChanges['product_sku']) || !Schema::hasTable('ch_ventacart_listing_links')) {
            return null;
        }

        $links = VentaCartListingLink::where('product_id', $productId)->get();
        if ($links->isEmpty()) {
            return null;
        }

        $old = $skuChanges['product_sku']['old'];
        $new = $skuChanges['product_sku']['new'];
        $results = [];

        $settingsById = VentaCartStore::query()->where('enabled', true)->get()->keyBy('id');

        foreach ($links as $link) {
            $store = $settingsById->get($link->store_id);
            if (!$store) {
                continue;
            }

            $linkSku = (string) ($link->sku ?: $old);
            try {
                $client = new VentaCartClient($store);
                $result = $client->updateProduct($linkSku, ['sku' => $new]);
                $ok = (bool) ($result['ok'] ?? false);

                if ($ok) {
                    $link->update(['sku' => $new]);
                }
                \Extensions\VentaCart\Services\VentaCart\VentaCartListingStates::for((int) $store->id)->recordOutcome($productId, $ok ? null
                    : 'SKU change to ' . $new . ' failed: ' . \Extensions\VentaCart\Services\VentaCart\VentaCartProductPush::failureText($result, $linkSku));

                $results[] = $store->store_name . ': ' . ($ok ? 'OK' : 'Failed');
            } catch (\Throwable $e) {
                Log::warning('SKU sync to VentaCart failed', [
                    'store' => $store->store_name,
                    'product_id' => $productId,
                    'sku_old' => $linkSku,
                    'sku_new' => $new,
                    'error' => $e->getMessage(),
                ]);
                \Extensions\VentaCart\Services\VentaCart\VentaCartListingStates::for((int) $store->id)->recordOutcome($productId,
                    'SKU change to ' . $new . ' failed: ' . \App\Support\TransportError::plain($e, 'VentaCart'));
                $results[] = $store->store_name . ': Error';
            }
        }

        return empty($results) ? null : 'VentaCart: ' . implode(', ', $results);
    }

    public function productActions(int $productId): array
    {
        if (! Schema::hasTable('ch_ventacart_stores')) {
            return [];
        }

        return VentaCartStore::query()
            ->where('enabled', true)
            ->orderBy('store_name')
            ->get(['id', 'store_name'])
            ->map(fn (VentaCartStore $s) => [
                'label' => 'Listing on ' . $s->store_name,
                'url' => route('ext.ventacart.listings.edit', [$s->id, $productId]),
                'icon' => 'store',
                'permission' => 'view_ventacart/listing',
            ])
            ->all();
    }

    public function saleLinesForOrder(int $coreOrderId, float $itemsSubtotal, float $shipping): ?array
    {
        $order = VentaCartOrder::query()->where('sales_order_id', $coreOrderId)->first();
        if (! $order) {
            return null;
        }
        $raw = is_array($order->raw) ? $order->raw : [];

        return \Extensions\VentaCart\Services\VentaCart\VentaCartSaleLines::lines(is_array($raw['totals'] ?? null) ? $raw['totals'] : [], $itemsSubtotal, $shipping);
    }

    public function feeBucketsForOrder(int $coreOrderId): ?array
    {
        if (! Schema::hasTable('ch_ventacart_orders')) {
            return null;
        }

        $row = DB::table('ch_ventacart_orders')->where('sales_order_id', $coreOrderId)->first(['raw']);
        if (! $row) {
            return null;
        }

        $raw = \App\Channels\Orders\FeePayload::decode($row->raw ?? null);
        $fees = \App\Channels\Orders\FeePayload::decode($raw['fees'] ?? []);

        $known = function (string $key) use ($fees): ?float {
            $value = $fees[$key] ?? null;

            return ($value === null || $value === '') ? null : abs((float) $value);
        };

        $payment = $known('payment_fee');
        $delivery = $known('shipping_cost');

        $coupon = 0.0;
        $totals = \App\Channels\Orders\FeePayload::decode($raw['totals'] ?? []);
        foreach (is_array($totals['lines'] ?? null) ? $totals['lines'] : [] as $line) {
            if (is_array($line) && ($line['code'] ?? '') === 'coupon') {
                $coupon += abs(\App\Support\Money::parse($line['amount'] ?? 0));
            }
        }

        if ($payment === null && $delivery === null && $coupon == 0.0) {
            return null;
        }

        return [
            'commission' => 0.0,
            'payment' => $payment ?? 0.0,
            'shipping' => $delivery ?? 0.0,
            'other' => 0.0,
            'voucher' => round($coupon, 2),
        ];
    }

    public function dashboardData(): array
    {
        $statuses = [];
        if (Schema::hasTable('ch_ventacart_listings')) {
            foreach ($this->allStores() as $store) {
                try {
                    $sum = \Extensions\VentaCart\Services\VentaCart\VentaCartListingStates::for((int) $store->id)->summary();
                } catch (\Throwable) {
                    continue;
                }
                $statuses[$this->id . ':' . $store->id] = (object) [
                    'last_order_sync_at' => $store->last_order_sync_at,
                    'listing_flags' => \App\Channels\Listings\ListingTrouble::figures(
                        $sum,
                        fn (string $bucket) => route('ext.ventacart.listings.index', ['store' => $store->id, 'state' => $bucket])
                    ),
                    'listing_attention' => (int) $sum['attention'],
                ];
            }
        }

        return [
            'channelSyncStatuses' => $statuses,
            'ventaCartStoreNames' => Schema::hasTable('ch_ventacart_stores')
                ? DB::table('ch_ventacart_stores')->pluck('store_name', 'id')->all()
                : [],
        ];
    }

    public function resolveSourceLabel(string $source): ?string
    {
        if (!str_starts_with($source, 'ventacart:')) {
            return null;
        }
        $storeId = (int) substr($source, 6);
        if ($storeId <= 0 || !Schema::hasTable('ch_ventacart_stores')) {
            return null;
        }
        $name = VentaCartStore::where('id', $storeId)->value('store_name');
        return \App\Support\StoreLabel::of('VentaCart', $name ?: ('VentaCart #' . $storeId));
    }

    public function availableStoreOptions(): array
    {
        return array_map(fn ($o) => [
            'key'        => (string) $o['value'],
            'label'      => (string) $o['label'],
            'channel'    => 'VentaCart',
            'source'     => (string) $o['value'],
            'store_id'   => null,
            'permission' => 'view_ventacart/order',
        ], $this->availableSourceOptions());
    }

    public function availableSourceOptions(): array
    {
        if (!Schema::hasTable('ch_ventacart_stores')) {
            return [];
        }
        return VentaCartStore::orderBy('id')->get(['id', 'store_name', 'brand_color'])
            ->map(fn ($s) => [
                'value'       => 'ventacart:' . $s->id,
                'label'       => \App\Support\StoreLabel::of('VentaCart', $s->store_name ?: ('VentaCart #' . $s->id)),
                'channel'     => 'VentaCart',
                'badge_class' => 'badge-green',
                'chart_color' => $s->brand_color ?: '#059669',
            ])->all();
    }

    use RevealsCredentials;

    public function revealableCredentials(): array
    {
        return [
            'api_token' => 'API token',
        ];
    }

    protected function credentialSettingsModel(): string
    {
        return \Extensions\VentaCart\Models\VentaCartStore::class;
    }

    protected function credentialIsMultiStore(): bool
    {
        return true;
    }

    public function credentialManagePermission(): string
    {
        return 'manage_ventacart/settings';
    }

    public function productPresence(array $productIds): array
    {
        return app(\Extensions\VentaCart\Services\VentaCart\VentaCartProductRemover::class)->presence($productIds);
    }

    public function removeProduct(int $productId): array
    {
        return app(\Extensions\VentaCart\Services\VentaCart\VentaCartProductRemover::class)->remove($productId);
    }

    public function strandedProductIds(): array
    {
        return app(\Extensions\VentaCart\Services\VentaCart\VentaCartProductRemover::class)->stranded();
    }

    public function automations(): array
    {
        return \Extensions\VentaCart\Support\VentaCartScheduledJobs::JOBS;
    }

    public function automationStoreIds(): ?array
    {
        return \Extensions\VentaCart\Models\VentaCartStore::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
