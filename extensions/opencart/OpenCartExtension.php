<?php

namespace Extensions\OpenCart;

use App\Extensions\ExtensionProvider;
use App\Channels\Contracts\DashboardContributor;
use App\Channels\Contracts\ChannelOptionsProvider;
use App\Channels\Contracts\OrderFeesContributor;
use App\Channels\Contracts\SkuSyncContributor;
use App\Models\Catalog\Order;
use App\Channels\Dto\RecentOrder;
use App\Channels\Dto\TopProduct;
use App\Channels\ChannelCard;
use App\Channels\ChannelProvider;
use App\Channels\ChannelRegistry;
use App\Channels\MenuItem;
use App\Channels\OrderTab;
use App\Channels\StoreLink;
use App\Models\Catalog\OrderStatus;
use Extensions\OpenCart\Models\OpenCartListingLink;
use Extensions\OpenCart\Models\OpenCartStore;
use Extensions\OpenCart\Models\OpenCartSyncLog;
use Extensions\OpenCart\Services\OpenCart\OpenCartClient;
use Extensions\OpenCart\Services\OpenCart\OpenCartProductSync;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

use App\Channels\Contracts\CredentialRevealer;
use App\Channels\Concerns\RevealsCredentials;

class OpenCartExtension extends ExtensionProvider implements ChannelProvider, SkuSyncContributor, DashboardContributor, OrderFeesContributor, ChannelOptionsProvider, CredentialRevealer, \App\Channels\Contracts\StoreOptionsProvider, \App\Channels\Contracts\ProductRemover
{
    protected string $id = 'opencart';

    public function boot(): void
    {
        parent::boot();

        $this->commands([
            \Extensions\OpenCart\Commands\OpenCartSync::class,
            \Extensions\OpenCart\Commands\OpenCartPush::class,
            \Extensions\OpenCart\Commands\OpenCartPushQty::class,
            \Extensions\OpenCart\Commands\OpenCartPushPrice::class,
            \Extensions\OpenCart\Commands\OpenCartPullQty::class,
            \Extensions\OpenCart\Commands\OpenCartImportOrders::class,
            \Extensions\OpenCart\Commands\OpenCartReclassifyOrders::class,
            \Extensions\OpenCart\Commands\OpenCartPushReviews::class,
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
            new ChannelCard(
                id: $this->id,
                name: 'OpenCart',
                tagline: 'Multi-store OpenCart. Add stores, sync products, push reviews.',
                icon: 'cart',
                accent: '#1f8fea',
                permission: 'view_opencart/dashboard',
                menu: [
                    new MenuItem('Reviews', 'ext.opencart.reviews.index', 'view_opencart/review'),
                ],
                addStore: new \App\Channels\AddStoreAction(
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
        if (!Schema::hasTable('ch_opencart_stores')) {
            return [];
        }
        return OpenCartStore::where('enabled', true)->orderBy('id')->get();
    }

    protected function allStores(): iterable
    {
        if (!Schema::hasTable('ch_opencart_stores')) {
            return [];
        }
        return OpenCartStore::orderBy('id')->get();
    }

    protected function buildOrderTab(int $storeId, string $storeName): OrderTab
    {
        $orderChannel = 'opencart:' . $storeId;
        $orderTable = 'orders';
        $productTable = 'order_products';

        return new OrderTab(
            id: $this->id . ':' . $storeId,
            label: $storeName,
            icon: 'cart',
            accent: '#1f8fea',
            routeName: 'ext.opencart.orders.index',
            permission: 'view_opencart/order',
            routeParams: ['store' => $storeId],
            unprocessedCounter: function () use ($orderChannel, $orderTable) {
                if (!Schema::hasTable($orderTable)) {
                    return 0;
                }
                $pendingStatusIds = OrderStatus::whereIn('name', ['Pending', 'Processing'])->pluck('order_status_id');
                if ($pendingStatusIds->isEmpty()) {
                    return 0;
                }
                return DB::table($orderTable)
                    ->whereIn('order_status_id', $pendingStatusIds)
                    ->where('channel', $orderChannel)
                    ->count();
            },
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
        if (!Schema::hasTable('ch_opencart_stores')) {
            return null;
        }

        $stores = OpenCartStore::where('enabled', true)->get();
        if ($stores->isEmpty()) {
            return null;
        }

        $results = [];
        foreach ($stores as $store) {
            $link = OpenCartListingLink::where('store_id', $store->id)
                ->where('product_id', $productId)
                ->first();
            if (!$link) {
                continue;
            }

            try {
                $client = new OpenCartClient($store);
                $sync = new OpenCartProductSync($client, $store);
                $result = $sync->push([$productId]);
                $ok = ($result['failed'] ?? 0) === 0;
                $results[] = $store->store_name . ': ' . ($ok ? 'OK' : 'Failed');
            } catch (\Throwable $e) {
                Log::warning('SKU sync to OpenCart failed', [
                    'store' => $store->store_name,
                    'product_id' => $productId,
                    'error' => $e->getMessage(),
                ]);
                $results[] = $store->store_name . ': Error';
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

        if (!Schema::hasTable('ch_opencart_stores')) {
            return $data;
        }

        $data['ocStoreNames'] = DB::table('ch_opencart_stores')->pluck('store_name', 'id')->all();

        if (!Schema::hasTable('opencart_sync_logs')) {
            return $data;
        }

        $pendingByStore = [];
        $orderTable = 'orders';
        if (Schema::hasTable($orderTable)) {
            $pendingStatusIds = OrderStatus::whereIn('name', ['Pending', 'Processing'])->pluck('order_status_id');
            if ($pendingStatusIds->isNotEmpty()) {
                $rows = DB::table($orderTable)
                    ->select('channel', DB::raw('COUNT(*) as cnt'))
                    ->whereIn('order_status_id', $pendingStatusIds)
                    ->where('channel', 'like', 'opencart:%')
                    ->groupBy('channel')
                    ->get();
                foreach ($rows as $row) {
                    $src = (string) $row->channel;
                    if (str_starts_with($src, 'opencart:')) {
                        $storeId = (int) str_replace('opencart:', '', $src);
                        if ($storeId > 0) {
                            $pendingByStore[$storeId] = (int) $row->cnt;
                        }
                    }
                }
            }
        }

        $data['syncStatuses'] = DB::table('ch_opencart_stores as s')
            ->where('s.enabled', true)
            ->get(['s.id', 's.store_name', 's.last_order_sync_at', 's.last_product_sync_at'])
            ->map(function ($store) use ($pendingByStore) {
                $lastLog = OpenCartSyncLog::where('store_id', $store->id)
                    ->orderByDesc('id')
                    ->first(['status', 'entity_type', 'completed_at', 'error_message']);
                $store->last_status = $lastLog->status ?? null;
                $store->last_entity = $lastLog->entity_type ?? null;
                $store->last_completed = $lastLog->completed_at ?? null;
                $store->last_error = $lastLog->error_message ?? null;

                $store->last_push_qty_at = OpenCartSyncLog::where('store_id', $store->id)
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
        $source = (string) $order->channel;
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
        if ($storeId <= 0 || !Schema::hasTable('ch_opencart_stores')) {
            return null;
        }
        $name = OpenCartStore::where('id', $storeId)->value('store_name');
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
        if (!Schema::hasTable('ch_opencart_stores')) {
            return [];
        }
        $palette = ['#16a34a', '#38bdf8', '#a855f7', '#f97316', '#0ea5e9', '#ec4899', '#65a30d'];
        return OpenCartStore::orderBy('id')->get(['id', 'store_name', 'brand_color'])
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
        return \Extensions\OpenCart\Models\OpenCartStore::class;
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
        return app(\Extensions\OpenCart\Services\OpenCart\OpenCartProductRemover::class)->presence($productIds);
    }

    public function removeProduct(int $productId): array
    {
        return app(\Extensions\OpenCart\Services\OpenCart\OpenCartProductRemover::class)->remove($productId);
    }

    public function strandedProductIds(): array
    {
        return app(\Extensions\OpenCart\Services\OpenCart\OpenCartProductRemover::class)->stranded();
    }

    public function automations(): array
    {
        return \Extensions\OpenCart\Support\OpenCartScheduledJobs::JOBS;
    }

    public function automationStoreIds(): ?array
    {
        return \Extensions\OpenCart\Models\OpenCartStore::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
