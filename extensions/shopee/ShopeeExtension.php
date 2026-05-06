<?php

namespace Extensions\shopee;

use App\Extensions\ExtensionProvider;
use App\Integrations\Dto\RecentOrder;
use App\Integrations\Dto\TopProduct;
use App\Integrations\IntegrationCard;
use App\Integrations\IntegrationProvider;
use App\Integrations\IntegrationRegistry;
use App\Integrations\MenuItem;
use App\Integrations\OrderTab;
use Extensions\shopee\Models\ShopeeOrder;
use Extensions\shopee\Models\ShopeeSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ShopeeExtension extends ExtensionProvider implements IntegrationProvider
{
    protected string $id = 'shopee';

    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Extensions\shopee\Commands\ShopeePushStock::class,
                \Extensions\shopee\Commands\ShopeeRefreshToken::class,
                \Extensions\shopee\Commands\ShopeeSyncOrders::class,
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
        return [
            new IntegrationCard(
                id: $this->id,
                name: 'Shopee',
                tagline: 'Shopee marketplace integration — OAuth, products, orders, returns.',
                icon: 'shopee',
                accent: '#ee4d2d',
                permission: 'manage_shopee',
                menu: [
                    new MenuItem('Settings', 'ext.shopee.index', 'manage_shopee'),
                    new MenuItem('Categories', 'ext.shopee.categories.index', 'manage_shopee'),
                    new MenuItem('Product Groups', 'ext.shopee.product-groups.index', 'manage_shopee'),
                    new MenuItem('Orders', 'ext.shopee.orders.index', 'manage_shopee_orders'),
                ],
            ),
        ];
    }

    public function orderTabs(): array
    {
        return [
            new OrderTab(
                id: $this->id,
                label: 'Shopee',
                icon: 'shopee',
                accent: '#ee4d2d',
                routeName: 'ext.shopee.orders.index',
                permission: 'manage_shopee_orders',
                unprocessedCounter: function () {
                    if (!Schema::hasTable('shopee_orders')) {
                        return 0;
                    }
                    $region = 'ph';
                    if (Schema::hasTable('shopee_settings')) {
                        $region = ShopeeSetting::query()->value('region') ?? 'ph';
                    }
                    return ShopeeOrder::query()
                        ->where('region', $region)
                        ->whereIn('status', ['READY_TO_SHIP'])
                        ->count();
                },
                dailyOrdersCounter: function () {
                    if (!Schema::hasTable('shopee_orders')) {
                        return 0;
                    }
                    return ShopeeOrder::query()
                        ->where('order_created_at', '>=', now()->startOfDay())
                        ->count();
                },
                dailyRevenueCounter: function () {
                    if (!Schema::hasTable('shopee_orders') || !Schema::hasTable('shopee_order_products')) {
                        return 0.0;
                    }
                    return (float) DB::table('shopee_order_products as p')
                        ->join('shopee_orders as o', 'o.id', '=', 'p.shopee_order_id')
                        ->where('o.order_created_at', '>=', now()->startOfDay())
                        ->sum(DB::raw('p.quantity * p.price'));
                },
                topProductsCallback: function (int $limit) {
                    if (!Schema::hasTable('shopee_orders') || !Schema::hasTable('shopee_order_products')) {
                        return [];
                    }
                    return DB::table('shopee_order_products as p')
                        ->join('shopee_orders as o', 'o.id', '=', 'p.shopee_order_id')
                        ->where('o.order_created_at', '>=', now()->startOfDay())
                        ->whereNotNull('p.sku')
                        ->where('p.sku', '!=', '')
                        ->groupBy('p.sku')
                        ->orderByDesc('qty_sold')
                        ->limit($limit)
                        ->select(
                            'p.sku',
                            DB::raw('MAX(p.name) as name'),
                            DB::raw('MAX(p.image) as image'),
                            DB::raw('SUM(p.quantity) as qty_sold'),
                            DB::raw('SUM(p.quantity * p.price) as revenue'),
                        )
                        ->get()
                        ->map(fn ($r) => new TopProduct(
                            sku: (string) $r->sku,
                            name: (string) ($r->name ?? $r->sku),
                            imageUrl: $r->image ?: null,
                            qtySold: (int) $r->qty_sold,
                            revenue: (float) $r->revenue,
                        ))->all();
                },
                recentOrdersCallback: function (int $limit) {
                    if (!Schema::hasTable('shopee_orders')) {
                        return [];
                    }
                    $orders = ShopeeOrder::query()
                        ->orderByDesc('order_created_at')
                        ->limit($limit)
                        ->get(['id', 'order_sn', 'status', 'order_created_at']);

                    $totals = DB::table('shopee_order_products')
                        ->whereIn('shopee_order_id', $orders->pluck('id'))
                        ->select('shopee_order_id', DB::raw('SUM(quantity * price) as total'))
                        ->groupBy('shopee_order_id')
                        ->pluck('total', 'shopee_order_id');

                    return $orders->map(fn ($o) => new RecentOrder(
                        reference: (string) $o->order_sn,
                        customerName: null,
                        total: (float) ($totals[$o->id] ?? 0),
                        statusLabel: (string) $o->status,
                        orderedAt: $o->order_created_at,
                        url: route('ext.shopee.orders.show', $o->order_sn),
                    ))->all();
                },
            ),
        ];
    }
}
