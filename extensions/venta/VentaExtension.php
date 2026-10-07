<?php

namespace Extensions\venta;

use App\Extensions\ExtensionProvider;
use App\Integrations\Contracts\DashboardContributor;
use App\Integrations\Contracts\ListingStateSource;
use App\Integrations\Contracts\MarketplaceSourceOptionsProvider;
use App\Integrations\Contracts\ProductActionContributor;
use App\Integrations\Contracts\SkuSyncContributor;
use App\Integrations\Dto\RecentOrder;
use App\Integrations\Dto\TopProduct;
use App\Integrations\IntegrationCard;
use App\Integrations\IntegrationProvider;
use App\Integrations\IntegrationRegistry;
use App\Integrations\MenuItem;
use App\Integrations\OrderTab;
use App\Integrations\StoreLink;
use App\Models\Catalog\OrderStatus;
use App\Support\FulfilmentSteps;
use Extensions\venta\Models\VentaOrder;
use Extensions\venta\Models\VentaProductLink;
use Extensions\venta\Models\VentaSetting;
use Extensions\venta\Services\Venta\VentaClient;
use Extensions\venta\Services\Venta\VentaStatusPlacements;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

use App\Integrations\Contracts\CredentialRevealer;
use App\Integrations\Concerns\RevealsCredentials;

class VentaExtension extends ExtensionProvider implements \App\Integrations\Contracts\MarketplaceFeeSource, IntegrationProvider, SkuSyncContributor, DashboardContributor, MarketplaceSourceOptionsProvider, CredentialRevealer, ProductActionContributor, \App\Integrations\Contracts\StoreOptionsProvider, \App\Integrations\Contracts\ProductRemover, ListingStateSource, \App\Integrations\Contracts\SaleLinesSource
{
    protected string $id = 'venta';

    public function boot(): void
    {
        parent::boot();

        $this->commands([
            \Extensions\venta\Commands\VentaSync::class,
            \Extensions\venta\Commands\VentaPushStock::class,
            \Extensions\venta\Commands\VentaPushPrice::class,
            \Extensions\venta\Commands\VentaPushReviews::class,
            \Extensions\venta\Commands\VentaRefreshListingStatus::class,
            \Extensions\venta\Commands\VentaFeeKeys::class,
            \Extensions\venta\Commands\VentaRefreshOrderFees::class,
        ]);

        $this->app->make(IntegrationRegistry::class)->register($this);
    }

    public function integrationId(): string
    {
        return $this->id;
    }

    public function integrationCards(): array
    {
        $stores = [];
        foreach ($this->allStores() as $store) {
            $stores[] = new StoreLink(
                id: $this->id . ':' . $store->id,
                label: $store->store_name,
                menu: [
                    new MenuItem('Settings', 'ext.venta.settings.show', 'view_venta/settings', null, ['store' => $store->id], 'Channel', children: [
                        ['label' => 'Connection', 'section' => 'connection'],
                        ['label' => 'Status mapping', 'section' => 'status'],
                        ['label' => 'Automations', 'section' => 'automations'],
                        ['label' => 'API log', 'section' => 'logs'],
                        ['label' => 'Sync log', 'section' => 'sync'],
                    ]),
                    new MenuItem('Listings', 'ext.venta.listings.index', 'view_venta/listing', null, ['store' => $store->id], 'Catalog'),
                    new MenuItem('Product Groups', 'ext.venta.product-groups.index', 'view_venta/product_group', null, ['store' => $store->id], 'Catalog'),
                    new MenuItem('Description Templates', 'ext.venta.description-templates.index', 'view_venta/description_template', null, ['store' => $store->id], 'Catalog'),
                    new MenuItem('Watermarks', 'ext.venta.watermarks.index', 'view_venta/watermark_template', null, ['store' => $store->id], 'Catalog'),
                    new MenuItem('Import', 'ext.venta.products.import', 'view_venta/listing', null, ['store' => $store->id], 'Catalog'),
                    new MenuItem('Orders', 'ext.venta.orders.index', 'view_venta/order', null, ['store' => $store->id], 'Sell'),
                ],
                status: $store->enabled ? 'active' : 'setup_pending',
            );
        }

        return [
            new IntegrationCard(
                id: $this->id,
                name: 'Venta',
                tagline: 'Multi-store ecommerce. Add stores, sync orders, push inventory.',
                icon: 'shop',
                accent: '#7c3aed',
                permission: 'view_venta/dashboard',
                menu: [],
                stores: $stores,
                addStore: new \App\Integrations\AddStoreAction(
                    route: 'ext.venta.stores.store',
                    permission: 'manage_venta/settings',
                    label: 'Add store',
                    fields: [
                        ['name' => 'base_url', 'label' => 'Store URL', 'type' => 'url', 'placeholder' => 'https://yourstore.venta.ph', 'hint' => 'The address of your Venta storefront.'],
                    ],
                ),
            ),
        ];
    }

    public function orderTabs(): array
    {
        $tabs = [];
        foreach ($this->enabledStores() as $store) {
            $tabs[] = $this->buildOrderTab((int) $store->id, \App\Support\StoreLabel::of('Venta', $store->store_name));
        }
        return $tabs;
    }

    public function listingStates(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === [] || ! Schema::hasTable('venta_listings')) {
            return [];
        }
        $out = [];
        foreach ($this->enabledStores() as $store) {
            $here = array_values(array_intersect($productIds, \Extensions\venta\Services\Venta\VentaStoreProducts::ids((int) $store->id)));
            $here = array_values(array_diff($here, array_keys($out)));
            if ($here === []) {
                continue;
            }
            foreach (\Extensions\venta\Services\Venta\VentaListingStates::for((int) $store->id)->forProducts($here) as $pid => $state) {
                $out[$pid] = $state;
            }
        }

        return $out;
    }

    public function listingUrls(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        $out = array_fill_keys($productIds, null);
        if ($productIds === [] || ! Schema::hasTable('venta_listings')) {
            return $out;
        }
        $first = null;
        foreach ($this->enabledStores() as $store) {
            $first ??= (int) $store->id;
            foreach (array_intersect($productIds, \Extensions\venta\Services\Venta\VentaStoreProducts::ids((int) $store->id)) as $pid) {
                $out[$pid] ??= route('ext.venta.listings.edit', [$store->id, $pid]);
            }
        }
        if ($first !== null) {
            foreach ($out as $pid => $url) {
                $out[$pid] = $url ?? route('ext.venta.listings.index', $first);
            }
        }

        return $out;
    }

    public function listedProductIds(): array
    {
        if (! Schema::hasTable('venta_product_links')) {
            return [];
        }

        return VentaProductLink::query()->whereNotNull('venta_product_id')
            ->distinct()->pluck('product_id')->map(fn ($v) => (int) $v)->all();
    }

    protected function enabledStores(): iterable
    {
        if (!Schema::hasTable('venta_settings')) {
            return [];
        }
        return VentaSetting::where('enabled', true)->orderBy('id')->get();
    }

    protected function allStores(): iterable
    {
        if (!Schema::hasTable('venta_settings')) {
            return [];
        }
        return VentaSetting::orderBy('id')->get();
    }

    public static function stepCounts(int $storeId): array
    {
        if (!Schema::hasTable('venta_orders')) {
            return array_fill_keys(FulfilmentSteps::placements(), 0);
        }

        $statusCounts = VentaOrder::query()
            ->where('venta_setting_id', $storeId)
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        return array_map(
            fn (array $bucket) => $bucket['count'],
            FulfilmentSteps::bucket('venta.orders', $statusCounts, VentaStatusPlacements::resolver($storeId))
        );
    }

    protected function buildOrderTab(int $storeId, string $storeName): OrderTab
    {
        $marketplaceSource = 'venta:' . $storeId;
        $orderTable = (string) config('catalog.prefix') . 'order';
        $productTable = (string) config('catalog.prefix') . 'order_product';

        return new OrderTab(
            id: $this->id . ':' . $storeId,
            label: $storeName,
            icon: 'shop',
            accent: '#7c3aed',
            routeName: 'ext.venta.orders.index',
            permission: 'view_venta/order',
            routeParams: ['store' => $storeId],
            unprocessedCounter: function () use ($storeId) {
                $steps = self::stepCounts($storeId);

                return $steps['to_pack'] + $steps['to_handover'];
            },
            stages: [
                ['key' => 'to_pack', 'label' => 'to pack', 'counter' => fn () => self::stepCounts($storeId)['to_pack']],
                ['key' => 'to_handover', 'label' => 'to handover', 'counter' => fn () => self::stepCounts($storeId)['to_handover']],
            ],
            dailyOrdersCounter: function () use ($marketplaceSource, $orderTable) {
                if (!Schema::hasTable($orderTable)) {
                    return 0;
                }
                return DB::table($orderTable)
                    ->where('marketplace_source', $marketplaceSource)
                    ->where('date_added', '>=', now()->startOfDay())
                    ->count();
            },
            dailyRevenueCounter: function () use ($marketplaceSource, $orderTable) {
                if (!Schema::hasTable($orderTable)) {
                    return 0.0;
                }
                return (float) DB::table($orderTable)
                    ->where('marketplace_source', $marketplaceSource)
                    ->where('date_added', '>=', now()->startOfDay())
                    ->sum('total');
            },
            topProductsCallback: function (int $limit) use ($marketplaceSource, $orderTable, $productTable) {
                if (!Schema::hasTable($orderTable) || !Schema::hasTable($productTable)) {
                    return [];
                }
                $catalogProductTable = (string) config('catalog.prefix') . 'product';
                return DB::table($productTable . ' as p')
                    ->join($orderTable . ' as o', 'o.order_id', '=', 'p.order_id')
                    ->leftJoin($catalogProductTable . ' as cp', 'cp.product_id', '=', 'p.product_id')
                    ->where('o.marketplace_source', $marketplaceSource)
                    ->where('o.date_added', '>=', now()->startOfDay())
                    ->whereNotNull('p.model')
                    ->where('p.model', '!=', '')
                    ->leftJoin(DB::raw('(SELECT order_product_id, GROUP_CONCAT(CONCAT(name, \': \', value) ORDER BY order_option_id SEPARATOR \', \') AS variation FROM `' . str_replace('order_product', 'order_option', $productTable) . '` GROUP BY order_product_id) AS oo'), 'oo.order_product_id', '=', 'p.order_product_id')
                    ->groupBy('p.model', 'oo.variation')
                    ->orderByDesc('qty_sold')->orderByDesc('revenue')->orderBy('name')
                    ->limit($limit)
                    ->select(
                        'p.model as sku',
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
            recentOrdersCallback: function (int $limit) use ($marketplaceSource, $orderTable) {
                if (!Schema::hasTable($orderTable)) {
                    return [];
                }
                $statuses = Schema::hasTable('order_status')
                    ? OrderStatus::query()->pluck('name', 'order_status_id')->all()
                    : [];

                return DB::table($orderTable)
                    ->where('marketplace_source', $marketplaceSource)
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
        if (empty($skuChanges['product_sku']) || !Schema::hasTable('venta_product_links')) {
            return null;
        }

        $links = VentaProductLink::where('product_id', $productId)->get();
        if ($links->isEmpty()) {
            return null;
        }

        $old = $skuChanges['product_sku']['old'];
        $new = $skuChanges['product_sku']['new'];
        $results = [];

        $settingsById = VentaSetting::query()->where('enabled', true)->get()->keyBy('id');

        foreach ($links as $link) {
            $setting = $settingsById->get($link->venta_setting_id);
            if (!$setting) {
                continue;
            }

            $linkSku = (string) ($link->sku ?: $old);
            try {
                $client = new VentaClient($setting);
                $result = $client->updateProduct($linkSku, ['sku' => $new]);
                $ok = (bool) ($result['ok'] ?? false);

                if ($ok) {
                    $link->update(['sku' => $new]);
                }
                \Extensions\venta\Services\Venta\VentaListingStates::for((int) $setting->id)->recordOutcome($productId, $ok ? null
                    : 'SKU change to ' . $new . ' failed: ' . \Extensions\venta\Services\Venta\VentaProductPush::failureText($result, $linkSku));

                $results[] = $setting->store_name . ': ' . ($ok ? 'OK' : 'Failed');
            } catch (\Throwable $e) {
                Log::warning('SKU sync to Venta failed', [
                    'store' => $setting->store_name,
                    'product_id' => $productId,
                    'sku_old' => $linkSku,
                    'sku_new' => $new,
                    'error' => $e->getMessage(),
                ]);
                \Extensions\venta\Services\Venta\VentaListingStates::for((int) $setting->id)->recordOutcome($productId,
                    'SKU change to ' . $new . ' failed: ' . \App\Support\TransportError::plain($e, 'Venta'));
                $results[] = $setting->store_name . ': Error';
            }
        }

        return empty($results) ? null : 'Venta: ' . implode(', ', $results);
    }

    public function productActions(int $productId): array
    {
        if (! Schema::hasTable('venta_settings')) {
            return [];
        }

        return VentaSetting::query()
            ->where('enabled', true)
            ->orderBy('store_name')
            ->get(['id', 'store_name'])
            ->map(fn (VentaSetting $s) => [
                'label' => 'Listing on ' . $s->store_name,
                'url' => route('ext.venta.listings.edit', [$s->id, $productId]),
                'icon' => 'store',
                'permission' => 'view_venta/listing',
            ])
            ->all();
    }

    public function saleLinesForOrder(int $coreOrderId, float $itemsSubtotal, float $shipping): ?array
    {
        $order = VentaOrder::query()->where('catalog_order_id', $coreOrderId)->first();
        if (! $order) {
            return null;
        }
        $raw = is_array($order->raw) ? $order->raw : [];

        return \Extensions\venta\Services\Venta\VentaSaleLines::lines(is_array($raw['totals'] ?? null) ? $raw['totals'] : [], $itemsSubtotal, $shipping);
    }

    public function feeBucketsForOrder(int $coreOrderId): ?array
    {
        if (! Schema::hasTable('venta_orders')) {
            return null;
        }

        $row = DB::table('venta_orders')->where('catalog_order_id', $coreOrderId)->first(['raw']);
        if (! $row) {
            return null;
        }

        $raw = \App\Integrations\Orders\FeePayload::decode($row->raw ?? null);
        $fees = \App\Integrations\Orders\FeePayload::decode($raw['fees'] ?? []);

        $known = function (string $key) use ($fees): ?float {
            $value = $fees[$key] ?? null;

            return ($value === null || $value === '') ? null : abs((float) $value);
        };

        $payment = $known('payment_fee');
        $delivery = $known('shipping_cost');

        $coupon = 0.0;
        $totals = \App\Integrations\Orders\FeePayload::decode($raw['totals'] ?? []);
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
        if (Schema::hasTable('venta_listings')) {
            foreach ($this->allStores() as $store) {
                try {
                    $sum = \Extensions\venta\Services\Venta\VentaListingStates::for((int) $store->id)->summary();
                } catch (\Throwable) {
                    continue;
                }
                $statuses[$this->id . ':' . $store->id] = (object) [
                    'last_order_sync_at' => $store->last_order_sync_at,
                    'listing_flags' => \App\Integrations\Listings\ListingTrouble::figures(
                        $sum,
                        fn (string $bucket) => route('ext.venta.listings.index', ['store' => $store->id, 'state' => $bucket])
                    ),
                    'listing_attention' => (int) $sum['attention'],
                ];
            }
        }

        return [
            'channelSyncStatuses' => $statuses,
            'ventaStoreNames' => Schema::hasTable('venta_settings')
                ? DB::table('venta_settings')->pluck('store_name', 'id')->all()
                : [],
        ];
    }

    public function resolveSourceLabel(string $source): ?string
    {
        if (!str_starts_with($source, 'venta:')) {
            return null;
        }
        $storeId = (int) substr($source, 6);
        if ($storeId <= 0 || !Schema::hasTable('venta_settings')) {
            return null;
        }
        $name = VentaSetting::where('id', $storeId)->value('store_name');
        return \App\Support\StoreLabel::of('Venta', $name ?: ('Venta #' . $storeId));
    }

    public function availableStoreOptions(): array
    {
        return array_map(fn ($o) => [
            'key'        => (string) $o['value'],
            'label'      => (string) $o['label'],
            'channel'    => 'Venta',
            'source'     => (string) $o['value'],
            'store_id'   => null,
            'permission' => 'view_venta/order',
        ], $this->availableSourceOptions());
    }

    public function availableSourceOptions(): array
    {
        if (!Schema::hasTable('venta_settings')) {
            return [];
        }
        return VentaSetting::orderBy('id')->get(['id', 'store_name', 'brand_color'])
            ->map(fn ($s) => [
                'value'       => 'venta:' . $s->id,
                'label'       => \App\Support\StoreLabel::of('Venta', $s->store_name ?: ('Venta #' . $s->id)),
                'channel'     => 'Venta',
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
        return \Extensions\venta\Models\VentaSetting::class;
    }

    protected function credentialIsMultiStore(): bool
    {
        return true;
    }

    public function credentialManagePermission(): string
    {
        return 'manage_venta/settings';
    }

    public function productPresence(array $productIds): array
    {
        return app(\Extensions\venta\Services\Venta\VentaProductRemover::class)->presence($productIds);
    }

    public function removeProduct(int $productId): array
    {
        return app(\Extensions\venta\Services\Venta\VentaProductRemover::class)->remove($productId);
    }

    public function strandedProductIds(): array
    {
        return app(\Extensions\venta\Services\Venta\VentaProductRemover::class)->stranded();
    }

    public function automations(): array
    {
        return \Extensions\venta\Support\VentaScheduledJobs::JOBS;
    }

    public function automationStoreIds(): ?array
    {
        return \Extensions\venta\Models\VentaSetting::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
