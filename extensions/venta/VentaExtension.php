<?php

namespace Extensions\venta;

use App\Extensions\ExtensionProvider;
use App\Integrations\Contracts\DashboardContributor;
use App\Integrations\Contracts\MarketplaceSourceOptionsProvider;
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
use Extensions\venta\Models\VentaProductLink;
use Extensions\venta\Models\VentaSetting;
use Extensions\venta\Services\Venta\VentaClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Venta is a multi-store integration: each enabled VentaSetting is its own
 * fulfillable surface. integrationCards() and orderTabs() return one entry
 * per enabled store, with metrics scoped to that store via the
 * marketplace_source = "venta:{id}" convention used elsewhere in the app.
 */
class VentaExtension extends ExtensionProvider implements IntegrationProvider, SkuSyncContributor, DashboardContributor, MarketplaceSourceOptionsProvider
{
    protected string $id = 'venta';

    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Extensions\venta\Commands\VentaSync::class,
                \Extensions\venta\Commands\VentaPushStock::class,
                \Extensions\venta\Commands\VentaPushReviews::class,
            ]);
        }

        $this->app->make(IntegrationRegistry::class)->register($this);
    }

    public function integrationId(): string
    {
        return $this->id;
    }

    public function integrationCards(): array
    {
        $stores = [];
        foreach ($this->enabledStores() as $store) {
            $stores[] = new StoreLink(
                id: $this->id . ':' . $store->id,
                label: $store->store_name,
                menu: [
                    new MenuItem('Settings', 'ext.venta.settings.show', 'manage_venta', null, ['store' => $store->id]),
                    new MenuItem('Product Groups', 'ext.venta.product-groups.index', 'manage_venta', null, ['store' => $store->id]),
                    new MenuItem('Orders', 'ext.venta.orders.index', 'manage_venta_orders', null, ['store' => $store->id]),
                ],
            );
        }

        return [
            new IntegrationCard(
                id: $this->id,
                name: 'Venta',
                tagline: 'Multi-store ecommerce — add stores, sync orders, push inventory.',
                icon: 'shop',
                accent: '#7c3aed',
                permission: 'manage_venta',
                menu: [],
                stores: $stores,
                addStore: new \App\Integrations\AddStoreAction(
                    route: 'ext.venta.stores.store',
                    permission: 'manage_venta',
                ),
            ),
        ];
    }

    public function orderTabs(): array
    {
        $tabs = [];
        foreach ($this->enabledStores() as $store) {
            $tabs[] = $this->buildOrderTab((int) $store->id, $store->store_name);
        }
        return $tabs;
    }

    /**
     * @return iterable<\Extensions\venta\Models\VentaSetting>
     */
    protected function enabledStores(): iterable
    {
        if (!Schema::hasTable('venta_settings')) {
            return [];
        }
        return VentaSetting::where('enabled', true)->orderBy('id')->get();
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
            permission: 'manage_venta_orders',
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
                    ->groupBy('p.model')
                    ->orderByDesc('qty_sold')
                    ->limit($limit)
                    ->select(
                        'p.model as sku',
                        DB::raw('MAX(p.name) as name'),
                        DB::raw('MAX(cp.image) as image'),
                        DB::raw('SUM(p.quantity) as qty_sold'),
                        DB::raw('SUM(p.total) as revenue'),
                    )
                    ->get()
                    ->map(fn ($r) => new TopProduct(
                        sku: (string) $r->sku,
                        name: (string) ($r->name ?? $r->sku),
                        imageUrl: $r->image ? asset('storage/' . ltrim($r->image, '/')) : null,
                        qtySold: (int) $r->qty_sold,
                        revenue: (float) $r->revenue,
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
                        statusLabel: (string) ($statuses[$o->order_status_id] ?? '—'),
                        orderedAt: $o->date_added ? new \DateTimeImmutable((string) $o->date_added) : null,
                        url: route('orders.show', $o->order_id),
                    ))->all();
            },
        );
    }

    /**
     * React to a SKU change on a catalog product by updating the
     * corresponding product on every linked Venta store. Venta's API uses
     * the existing SKU as the path key — PUT /products/{old_sku} with
     * { sku: new_sku } in the body — so we look up each linked store's
     * VentaProductLink, push the rename, and update the link locally.
     */
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

                $results[] = $setting->store_name . ': ' . ($ok ? 'OK' : 'Failed');
            } catch (\Throwable $e) {
                Log::warning('SKU sync to Venta failed', [
                    'store' => $setting->store_name,
                    'product_id' => $productId,
                    'sku_old' => $linkSku,
                    'sku_new' => $new,
                    'error' => $e->getMessage(),
                ]);
                $results[] = $setting->store_name . ': Error';
            }
        }

        return empty($results) ? null : 'Venta: ' . implode(', ', $results);
    }

    /**
     * Venta widgets for the core dashboard: store-name lookup table for
     * the "Recent orders" and platform breakdown labels.
     */
    public function dashboardData(): array
    {
        return [
            'ventaStoreNames' => Schema::hasTable('venta_settings')
                ? DB::table('venta_settings')->pluck('store_name', 'id')->all()
                : [],
        ];
    }

    /**
     * Resolve venta:N marketplace_source strings to the configured store name.
     */
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
        return $name ?: ('Venta #' . $storeId);
    }

    public function availableSourceOptions(): array
    {
        if (!Schema::hasTable('venta_settings')) {
            return [];
        }
        return VentaSetting::orderBy('id')->get(['id', 'store_name'])
            ->map(fn ($s) => [
                'value'       => 'venta:' . $s->id,
                'label'       => $s->store_name ?: ('Venta #' . $s->id),
                'badge_class' => 'badge-green',
                'chart_color' => '#059669',
            ])->all();
    }
}
