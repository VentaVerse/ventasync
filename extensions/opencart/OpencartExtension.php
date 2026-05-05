<?php

namespace Extensions\opencart;

use App\Extensions\ExtensionProvider;
use App\Integrations\Dto\RecentOrder;
use App\Integrations\Dto\TopProduct;
use App\Integrations\IntegrationCard;
use App\Integrations\IntegrationRegistry;
use App\Integrations\MenuItem;
use App\Integrations\OrderTab;
use App\Integrations\SummaryProvider;
use App\Models\Catalog\OrderStatus;
use Extensions\opencart\Models\OpenCartSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OpencartExtension extends ExtensionProvider implements SummaryProvider
{
    protected string $id = 'opencart';

    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Extensions\opencart\Commands\OpenCartSync::class,
                \Extensions\opencart\Commands\OpenCartPush::class,
                \Extensions\opencart\Commands\OpenCartPushQty::class,
                \Extensions\opencart\Commands\OpenCartPullQty::class,
                \Extensions\opencart\Commands\OpenCartImportOrders::class,
                \Extensions\opencart\Commands\OpenCartReclassifyOrders::class,
                \Extensions\opencart\Commands\OpenCartMigrateCosts::class,
                \Extensions\opencart\Commands\ShopeeSyncReviews::class,
                \Extensions\opencart\Commands\LazadaSyncReviews::class,
                \Extensions\opencart\Commands\OpenCartPushReviews::class,
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
            name: 'OpenCart',
            tagline: 'OpenCart store integration — multi-store, product sync, reviews.',
            icon: 'cart',
            accent: '#1f8fea',
            permission: 'manage_opencart',
            menu: [
                new MenuItem('Settings', 'ext.opencart.index', 'manage_opencart'),
                new MenuItem('Reviews', 'ext.opencart.reviews.index', 'manage_reviews'),
            ],
        );
    }

    public function orderTab(): ?OrderTab
    {
        // Multi-store: tab links to first enabled store; null if no stores configured.
        $firstStoreId = $this->firstEnabledStoreId();
        if ($firstStoreId === null) {
            return null;
        }

        return new OrderTab(
            id: $this->id,
            label: 'OpenCart',
            icon: 'cart',
            accent: '#1f8fea',
            routeName: 'ext.opencart.orders.index',
            routeParams: ['store' => $firstStoreId],
            permission: 'manage_opencart_orders',
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
                    ->where('marketplace_source', 'like', 'opencart:%')
                    ->count();
            },
        );
    }

    protected function firstEnabledStoreId(): ?int
    {
        if (!Schema::hasTable('opencart_settings')) {
            return null;
        }

        return OpenCartSetting::where('enabled', true)->orderBy('id')->value('id');
    }

    public function dailyOrdersCount(): int
    {
        $orderTable = (string) config('catalog.prefix') . 'order';
        if (!Schema::hasTable($orderTable)) {
            return 0;
        }
        return DB::table($orderTable)
            ->where('marketplace_source', 'like', 'opencart:%')
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
            ->where('marketplace_source', 'like', 'opencart:%')
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
            ->where('o.marketplace_source', 'like', 'opencart:%')
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
            ->where('marketplace_source', 'like', 'opencart:%')
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
