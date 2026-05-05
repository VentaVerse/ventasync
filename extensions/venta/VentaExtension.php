<?php

namespace Extensions\venta;

use App\Extensions\ExtensionProvider;
use App\Integrations\Dto\RecentOrder;
use App\Integrations\Dto\TopProduct;
use App\Integrations\IntegrationCard;
use App\Integrations\IntegrationRegistry;
use App\Integrations\MenuItem;
use App\Integrations\OrderTab;
use App\Integrations\SummaryProvider;
use App\Models\Catalog\OrderStatus;
use Extensions\venta\Models\VentaSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class VentaExtension extends ExtensionProvider implements SummaryProvider
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

    public function integrationCard(): ?IntegrationCard
    {
        return new IntegrationCard(
            id: $this->id,
            name: 'Venta Store',
            tagline: 'Venta ecommerce — multi-store order sync, inventory, reviews.',
            icon: 'shop',
            accent: '#7c3aed',
            permission: 'manage_venta',
            menu: [
                new MenuItem('Settings', 'ext.venta.index', 'manage_venta'),
            ],
        );
    }

    public function orderTab(): ?OrderTab
    {
        $firstStoreId = $this->firstEnabledStoreId();
        if ($firstStoreId === null) {
            return null;
        }

        return new OrderTab(
            id: $this->id,
            label: 'Venta',
            icon: 'shop',
            accent: '#7c3aed',
            routeName: 'ext.venta.orders.index',
            routeParams: ['store' => $firstStoreId],
            permission: 'manage_venta_orders',
            unprocessedCounter: function () {
                $pfx = (string) config('catalog.prefix');
                $orderTable = $pfx . 'order';
                if (!Schema::hasTable($orderTable)) {
                    return 0;
                }
                $pendingStatusIds = OrderStatus::whereIn('name', ['Pending', 'Processing'])->pluck('order_status_id');
                if ($pendingStatusIds->isEmpty()) {
                    return 0;
                }
                return DB::table($orderTable)
                    ->whereIn('order_status_id', $pendingStatusIds)
                    ->where('marketplace_source', 'like', 'venta:%')
                    ->count();
            },
        );
    }

    protected function firstEnabledStoreId(): ?int
    {
        if (!Schema::hasTable('venta_settings')) {
            return null;
        }

        return VentaSetting::where('enabled', true)->orderBy('id')->value('id');
    }

    public function dailyOrdersCount(): int
    {
        $orderTable = (string) config('catalog.prefix') . 'order';
        if (!Schema::hasTable($orderTable)) {
            return 0;
        }
        return DB::table($orderTable)
            ->where('marketplace_source', 'like', 'venta:%')
            ->where('date_added', '>=', now()->startOfDay())
            ->count();
    }

    public function dailyRevenue(): float
    {
        $orderTable = (string) config('catalog.prefix') . 'order';
        if (!Schema::hasTable($orderTable)) {
            return 0.0;
        }
        return (float) DB::table($orderTable)
            ->where('marketplace_source', 'like', 'venta:%')
            ->where('date_added', '>=', now()->startOfDay())
            ->sum('total');
    }

    public function topProductsToday(int $limit = 5): array
    {
        $pfx = (string) config('catalog.prefix');
        $orderTable = $pfx . 'order';
        $productTable = $pfx . 'order_product';
        if (!Schema::hasTable($orderTable) || !Schema::hasTable($productTable)) {
            return [];
        }
        $rows = DB::table($productTable . ' as p')
            ->join($orderTable . ' as o', 'o.order_id', '=', 'p.order_id')
            ->where('o.marketplace_source', 'like', 'venta:%')
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
            ->get();

        return $rows->map(fn ($r) => new TopProduct(
            sku: (string) $r->sku,
            name: (string) ($r->name ?? $r->sku),
            imageUrl: null,
            qtySold: (int) $r->qty_sold,
            revenue: (float) $r->revenue,
        ))->all();
    }

    public function recentOrders(int $limit = 5): array
    {
        $orderTable = (string) config('catalog.prefix') . 'order';
        if (!Schema::hasTable($orderTable)) {
            return [];
        }
        $statuses = Schema::hasTable('order_status')
            ? OrderStatus::query()->pluck('name', 'order_status_id')->all()
            : [];

        return DB::table($orderTable)
            ->where('marketplace_source', 'like', 'venta:%')
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
    }
}
