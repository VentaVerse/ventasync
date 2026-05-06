<?php

namespace Extensions\lazada;

use App\Extensions\ExtensionProvider;
use App\Integrations\Dto\RecentOrder;
use App\Integrations\Dto\TopProduct;
use App\Integrations\IntegrationCard;
use App\Integrations\IntegrationProvider;
use App\Integrations\IntegrationRegistry;
use App\Integrations\MenuItem;
use App\Integrations\OrderTab;
use Extensions\lazada\Models\LazadaOrder;
use Extensions\lazada\Models\LazadaSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LazadaExtension extends ExtensionProvider implements IntegrationProvider
{
    protected string $id = 'lazada';

    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Extensions\lazada\Commands\LazadaPushStock::class,
                \Extensions\lazada\Commands\LazadaRefreshToken::class,
                \Extensions\lazada\Commands\LazadaSyncOrders::class,
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
                name: 'Lazada',
                tagline: 'Lazada marketplace integration — products, orders, returns.',
                icon: 'lazada',
                accent: '#0f146d',
                permission: 'manage_lazada',
                menu: [
                    new MenuItem('Settings', 'ext.lazada.index', 'manage_lazada'),
                    new MenuItem('Product Groups', 'ext.lazada.product-groups.index', 'manage_lazada'),
                    new MenuItem('Orders', 'ext.lazada.orders.index', 'manage_lazada_orders', null, ['tab' => 'TO_SHIP']),
                ],
            ),
        ];
    }

    public function orderTabs(): array
    {
        return [
            new OrderTab(
                id: $this->id,
                label: 'Lazada',
                icon: 'lazada',
                accent: '#0f146d',
                routeName: 'ext.lazada.orders.index',
                permission: 'manage_lazada_orders',
                routeParams: ['tab' => 'TO_SHIP'],
                unprocessedCounter: function () {
                    if (!Schema::hasTable('lazada_orders')) {
                        return 0;
                    }
                    $region = 'ph';
                    if (Schema::hasTable('lazada_settings')) {
                        $region = LazadaSetting::query()->value('region') ?? 'ph';
                    }
                    return LazadaOrder::query()
                        ->where('region', $region)
                        ->whereIn('status', ['pending', 'repacked'])
                        ->count();
                },
                dailyOrdersCounter: function () {
                    if (!Schema::hasTable('lazada_orders')) {
                        return 0;
                    }
                    return LazadaOrder::query()
                        ->where('order_created_at', '>=', now()->startOfDay())
                        ->count();
                },
                dailyRevenueCounter: function () {
                    if (!Schema::hasTable('lazada_orders') || !Schema::hasTable('lazada_order_products')) {
                        return 0.0;
                    }
                    return (float) DB::table('lazada_order_products as p')
                        ->join('lazada_orders as o', 'o.id', '=', 'p.lazada_order_id')
                        ->where('o.order_created_at', '>=', now()->startOfDay())
                        ->sum(DB::raw('p.quantity * COALESCE(p.paid_price, p.item_price)'));
                },
                topProductsCallback: function (int $limit) {
                    if (!Schema::hasTable('lazada_orders') || !Schema::hasTable('lazada_order_products')) {
                        return [];
                    }
                    return DB::table('lazada_order_products as p')
                        ->join('lazada_orders as o', 'o.id', '=', 'p.lazada_order_id')
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
                            DB::raw('SUM(p.quantity * COALESCE(p.paid_price, p.item_price)) as revenue'),
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
                    if (!Schema::hasTable('lazada_orders')) {
                        return [];
                    }
                    $orders = LazadaOrder::query()
                        ->orderByDesc('order_created_at')
                        ->limit($limit)
                        ->get(['id', 'order_id', 'status', 'order_created_at']);

                    $totals = DB::table('lazada_order_products')
                        ->whereIn('lazada_order_id', $orders->pluck('id'))
                        ->select('lazada_order_id', DB::raw('SUM(quantity * COALESCE(paid_price, item_price)) as total'))
                        ->groupBy('lazada_order_id')
                        ->pluck('total', 'lazada_order_id');

                    return $orders->map(fn ($o) => new RecentOrder(
                        reference: (string) $o->order_id,
                        customerName: null,
                        total: (float) ($totals[$o->id] ?? 0),
                        statusLabel: (string) $o->status,
                        orderedAt: $o->order_created_at,
                        url: route('ext.lazada.orders.show', $o->order_id),
                    ))->all();
                },
            ),
        ];
    }
}
