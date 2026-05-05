<?php

namespace Extensions\venta;

use App\Extensions\ExtensionProvider;
use App\Integrations\Dto\RecentOrder;
use App\Integrations\Dto\TopProduct;
use App\Integrations\IntegrationCard;
use App\Integrations\IntegrationProvider;
use App\Integrations\IntegrationRegistry;
use App\Integrations\MenuItem;
use App\Integrations\OrderTab;
use App\Models\Catalog\OrderStatus;
use Extensions\venta\Models\VentaSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Venta is a multi-store integration: each enabled VentaSetting is its own
 * fulfillable surface. integrationCards() and orderTabs() return one entry
 * per enabled store, with metrics scoped to that store via the
 * marketplace_source = "venta:{id}" convention used elsewhere in the app.
 */
class VentaExtension extends ExtensionProvider implements IntegrationProvider
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
        // Management card — always visible when the extension is enabled+licensed.
        // Holds the global Settings link so an admin can add the first store
        // even before any are configured.
        $cards = [
            new IntegrationCard(
                id: $this->id,
                name: 'Venta',
                tagline: 'Add and configure Venta stores.',
                icon: 'shop',
                accent: '#7c3aed',
                permission: 'manage_venta',
                menu: [
                    new MenuItem('Settings', 'ext.venta.index', 'manage_venta'),
                ],
            ),
        ];

        foreach ($this->enabledStores() as $store) {
            $cards[] = new IntegrationCard(
                id: $this->id . ':' . $store->id,
                name: $store->store_name,
                tagline: 'Venta store · orders and product groups.',
                icon: 'shop',
                accent: '#7c3aed',
                permission: 'manage_venta',
                menu: [
                    new MenuItem('Product Groups', 'ext.venta.product-groups.index', 'manage_venta', null, ['store' => $store->id]),
                    new MenuItem('Orders', 'ext.venta.orders.index', 'manage_venta_orders', null, ['store' => $store->id]),
                ],
            );
        }
        return $cards;
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
                return DB::table($productTable . ' as p')
                    ->join($orderTable . ' as o', 'o.order_id', '=', 'p.order_id')
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
                        DB::raw('SUM(p.quantity) as qty_sold'),
                        DB::raw('SUM(p.total) as revenue'),
                    )
                    ->get()
                    ->map(fn ($r) => new TopProduct(
                        sku: (string) $r->sku,
                        name: (string) ($r->name ?? $r->sku),
                        imageUrl: null,
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
}
