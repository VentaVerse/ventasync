<?php

namespace Extensions\opencart;

use App\Extensions\ExtensionProvider;
use App\Integrations\Contracts\DashboardContributor;
use App\Integrations\Contracts\MarketplaceSourceOptionsProvider;
use App\Integrations\Contracts\OrderFeesContributor;
use App\Integrations\Contracts\SkuSyncContributor;
use App\Models\Catalog\Order;
use App\Integrations\Dto\RecentOrder;
use App\Integrations\Dto\TopProduct;
use App\Integrations\IntegrationCard;
use App\Integrations\IntegrationProvider;
use App\Integrations\IntegrationRegistry;
use App\Integrations\MenuItem;
use App\Integrations\OrderTab;
use App\Integrations\StoreLink;
use App\Models\Catalog\OrderStatus;
use Extensions\opencart\Models\OpenCartProductLink;
use Extensions\opencart\Models\OpenCartSetting;
use Extensions\opencart\Models\OpenCartSyncLog;
use Extensions\opencart\Services\OpenCart\OpenCartClient;
use Extensions\opencart\Services\OpenCart\OpenCartProductSync;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

use App\Integrations\Contracts\CredentialRevealer;
use App\Integrations\Concerns\RevealsCredentials;

class OpencartExtension extends ExtensionProvider implements IntegrationProvider, SkuSyncContributor, DashboardContributor, OrderFeesContributor, MarketplaceSourceOptionsProvider, CredentialRevealer, \App\Integrations\Contracts\StoreOptionsProvider, \App\Integrations\Contracts\ProductRemover
{
    protected string $id = 'opencart';

    public function boot(): void
    {
        parent::boot();

        $this->commands([
            \Extensions\opencart\Commands\OpenCartSync::class,
            \Extensions\opencart\Commands\OpenCartPush::class,
            \Extensions\opencart\Commands\OpenCartPushQty::class,
            \Extensions\opencart\Commands\OpenCartPushPrice::class,
            \Extensions\opencart\Commands\OpenCartPullQty::class,
            \Extensions\opencart\Commands\OpenCartImportOrders::class,
            \Extensions\opencart\Commands\OpenCartReclassifyOrders::class,
            \Extensions\opencart\Commands\OpenCartPushReviews::class,
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
                    new MenuItem('Settings', 'ext.opencart.settings.show', 'view_opencart/settings', null, ['store' => $store->id], 'Channel', children: [
                        ['label' => 'Connection', 'section' => 'connection'],
                        ['label' => 'Order sync', 'section' => 'orders'],
                        ['label' => 'Status mapping', 'section' => 'status'],
                        ['label' => 'Automations', 'section' => 'automations'],
                        ['label' => 'First import', 'section' => 'import', 'permission' => 'manage_opencart/settings'],
                        ['label' => 'Sync log', 'section' => 'sync'],
                    ]),
                    new MenuItem('Product Groups', 'ext.opencart.product-groups.index', 'view_opencart/product_group', null, ['store' => $store->id], 'Catalog'),
                    new MenuItem('Import', 'ext.opencart.products.import', 'view_opencart/import', null, ['store' => $store->id], 'Catalog'),
                    new MenuItem('Orders', 'ext.opencart.orders.index', 'view_opencart/order', null, ['store' => $store->id], 'Sell'),
                ],
                status: $store->enabled ? 'active' : 'setup_pending',
            );
        }

        return [
            new IntegrationCard(
                id: $this->id,
                name: 'OpenCart',
                tagline: 'Multi-store OpenCart. Add stores, sync products, push reviews.',
                icon: 'cart',
                accent: '#1f8fea',
                permission: 'view_opencart/dashboard',
                menu: [
                    new MenuItem('Reviews', 'ext.opencart.reviews.index', 'view_opencart/review'),
                ],
                addStore: new \App\Integrations\AddStoreAction(
                    route: 'ext.opencart.stores.store',
                    permission: 'manage_opencart/settings',
                    label: 'Add store',
                    fields: [
                        ['name' => 'base_url', 'label' => 'Store URL', 'type' => 'url', 'placeholder' => 'https://shop.example.com', 'hint' => 'The base address of your OpenCart shop.'],
                    ],
                ),
                stores: $stores,
            ),
        ];
    }

    public function orderTabs(): array
    {
        $tabs = [];
        foreach ($this->enabledStores() as $store) {
            $tabs[] = $this->buildOrderTab((int) $store->id, \App\Support\StoreLabel::of('OpenCart', $store->store_name));
        }
        return $tabs;
    }

    protected function enabledStores(): iterable
    {
        if (!Schema::hasTable('opencart_settings')) {
            return [];
        }
        return OpenCartSetting::where('enabled', true)->orderBy('id')->get();
    }

    protected function allStores(): iterable
    {
        if (!Schema::hasTable('opencart_settings')) {
            return [];
        }
        return OpenCartSetting::orderBy('id')->get();
    }

    protected function buildOrderTab(int $storeId, string $storeName): OrderTab
    {
        $marketplaceSource = 'opencart:' . $storeId;
        $orderTable = (string) config('catalog.prefix') . 'order';
        $productTable = (string) config('catalog.prefix') . 'order_product';

        return new OrderTab(
            id: $this->id . ':' . $storeId,
            label: $storeName,
            icon: 'cart',
            accent: '#1f8fea',
            routeName: 'ext.opencart.orders.index',
            permission: 'view_opencart/order',
            routeParams: ['store' => $storeId],
            unprocessedCounter: function () use ($marketplaceSource, $orderTable) {
                if (!Schema::hasTable($orderTable)) {
                    return 0;
                }
                $pendingStatusIds = OrderStatus::whereIn('name', ['Pending', 'Processing'])->pluck('order_status_id');
                if ($pendingStatusIds->isEmpty()) {
                    return 0;
                }
                return DB::table($orderTable)
                    ->whereIn('order_status_id', $pendingStatusIds)
                    ->where('marketplace_source', $marketplaceSource)
                    ->count();
            },
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
        if (!Schema::hasTable('opencart_settings')) {
            return null;
        }

        $stores = OpenCartSetting::where('enabled', true)->get();
        if ($stores->isEmpty()) {
            return null;
        }

        $results = [];
        foreach ($stores as $setting) {
            $link = OpenCartProductLink::where('opencart_setting_id', $setting->id)
                ->where('product_id', $productId)
                ->first();
            if (!$link) {
                continue;
            }

            try {
                $client = new OpenCartClient($setting);
                $sync = new OpenCartProductSync($client, $setting);
                $result = $sync->push([$productId]);
                $ok = ($result['failed'] ?? 0) === 0;
                $results[] = $setting->store_name . ': ' . ($ok ? 'OK' : 'Failed');
            } catch (\Throwable $e) {
                Log::warning('SKU sync to OpenCart failed', [
                    'store' => $setting->store_name,
                    'product_id' => $productId,
                    'error' => $e->getMessage(),
                ]);
                $results[] = $setting->store_name . ': Error';
            }
        }

        return empty($results) ? null : 'OpenCart: ' . implode(', ', $results);
    }

    public function dashboardData(): array
    {
        $data = [
            'ocStoreNames' => [],
            'syncStatuses' => collect(),
        ];

        if (!Schema::hasTable('opencart_settings')) {
            return $data;
        }

        $data['ocStoreNames'] = DB::table('opencart_settings')->pluck('store_name', 'id')->all();

        if (!Schema::hasTable('opencart_sync_logs')) {
            return $data;
        }

        $pendingByStore = [];
        $orderTable = (string) config('catalog.prefix') . 'order';
        if (Schema::hasTable($orderTable)) {
            $pendingStatusIds = OrderStatus::whereIn('name', ['Pending', 'Processing'])->pluck('order_status_id');
            if ($pendingStatusIds->isNotEmpty()) {
                $rows = DB::table($orderTable)
                    ->select('marketplace_source', DB::raw('COUNT(*) as cnt'))
                    ->whereIn('order_status_id', $pendingStatusIds)
                    ->where('marketplace_source', 'like', 'opencart:%')
                    ->groupBy('marketplace_source')
                    ->get();
                foreach ($rows as $row) {
                    $src = (string) $row->marketplace_source;
                    if (str_starts_with($src, 'opencart:')) {
                        $storeId = (int) str_replace('opencart:', '', $src);
                        if ($storeId > 0) {
                            $pendingByStore[$storeId] = (int) $row->cnt;
                        }
                    }
                }
            }
        }

        $data['syncStatuses'] = DB::table('opencart_settings as s')
            ->where('s.enabled', true)
            ->get(['s.id', 's.store_name', 's.last_order_sync_at', 's.last_product_sync_at'])
            ->map(function ($store) use ($pendingByStore) {
                $lastLog = OpenCartSyncLog::where('opencart_setting_id', $store->id)
                    ->orderByDesc('id')
                    ->first(['status', 'entity_type', 'completed_at', 'error_message']);
                $store->last_status = $lastLog->status ?? null;
                $store->last_entity = $lastLog->entity_type ?? null;
                $store->last_completed = $lastLog->completed_at ?? null;
                $store->last_error = $lastLog->error_message ?? null;

                $store->last_push_qty_at = OpenCartSyncLog::where('opencart_setting_id', $store->id)
                    ->where('entity_type', 'product_qty')
                    ->where('direction', 'push')
                    ->where('status', 'completed')
                    ->orderByDesc('id')
                    ->value('completed_at');

                $store->pending_count = $pendingByStore[$store->id] ?? 0;
                return $store;
            });

        return $data;
    }

    public function feesForOrder(Order $order): ?array
    {
        $source = (string) $order->marketplace_source;
        if (!str_starts_with($source, 'opencart:')) {
            return null;
        }

        $items = [];
        $total = 0;
        $paymentCost = (float) ($order->payment_cost ?? 0);
        $extraCost = (float) ($order->extra_cost ?? 0);

        if ($paymentCost > 0) { $items[] = ['label' => 'Payment Fee', 'amount' => $paymentCost]; $total += $paymentCost; }
        if ($extraCost > 0)   { $items[] = ['label' => 'Extra Cost',  'amount' => $extraCost];   $total += $extraCost; }

        return ['total' => round($total, 2), 'items' => $items, 'source' => 'opencart'];
    }

    public function resolveSourceLabel(string $source): ?string
    {
        if (!str_starts_with($source, 'opencart:')) {
            return null;
        }
        $storeId = (int) substr($source, 9);
        if ($storeId <= 0 || !Schema::hasTable('opencart_settings')) {
            return null;
        }
        $name = OpenCartSetting::where('id', $storeId)->value('store_name');
        return $name ?: ('OpenCart #' . $storeId);
    }

    public function availableStoreOptions(): array
    {
        return array_map(fn ($o) => [
            'key'        => (string) $o['value'],
            'label'      => (string) $o['label'],
            'channel'    => 'OpenCart',
            'source'     => (string) $o['value'],
            'store_id'   => null,
            'permission' => 'view_opencart/order',
        ], $this->availableSourceOptions());
    }

    public function availableSourceOptions(): array
    {
        if (!Schema::hasTable('opencart_settings')) {
            return [];
        }
        $palette = ['#16a34a', '#38bdf8', '#a855f7', '#f97316', '#0ea5e9', '#ec4899', '#65a30d'];
        return OpenCartSetting::orderBy('id')->get(['id', 'store_name', 'brand_color'])
            ->map(fn ($s) => [
                'value'       => 'opencart:' . $s->id,
                'label'       => \App\Support\StoreLabel::of('OpenCart', $s->store_name ?: ('OpenCart #' . $s->id)),
                'channel'     => 'OpenCart',
                'badge_class' => 'badge-dark',
                'chart_color' => $s->brand_color ?: $palette[($s->id - 1) % count($palette)],
            ])->all();
    }

    use RevealsCredentials;

    public function revealableCredentials(): array
    {
        return [
            'api_key' => 'API key',
        ];
    }

    protected function credentialSettingsModel(): string
    {
        return \Extensions\opencart\Models\OpenCartSetting::class;
    }

    protected function credentialIsMultiStore(): bool
    {
        return true;
    }

    public function credentialManagePermission(): string
    {
        return 'manage_opencart/settings';
    }

    public function productPresence(array $productIds): array
    {
        return app(\Extensions\opencart\Services\OpenCart\OpenCartProductRemover::class)->presence($productIds);
    }

    public function removeProduct(int $productId): array
    {
        return app(\Extensions\opencart\Services\OpenCart\OpenCartProductRemover::class)->remove($productId);
    }

    public function strandedProductIds(): array
    {
        return app(\Extensions\opencart\Services\OpenCart\OpenCartProductRemover::class)->stranded();
    }

    public function automations(): array
    {
        return \Extensions\opencart\Support\OpencartScheduledJobs::JOBS;
    }

    public function automationStoreIds(): ?array
    {
        return \Extensions\opencart\Models\OpenCartSetting::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
