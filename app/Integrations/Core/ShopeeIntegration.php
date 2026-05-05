<?php

namespace App\Integrations\Core;

use App\Integrations\Dto\RecentOrder;
use App\Integrations\Dto\TopProduct;
use App\Integrations\IntegrationCard;
use App\Integrations\IntegrationProvider;
use App\Integrations\MenuItem;
use App\Integrations\OrderTab;
use App\Models\ShopeeOrder;
use App\Models\ShopeeSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TEMPORARY core integration provider for Shopee.
 *
 * Shopee currently lives in app/Http/Controllers/ rather than extensions/ — this
 * provider lets the new Integrations/Orders pages discover Shopee uniformly via
 * the registry. When Shopee is moved into extensions/shopee/ (planned), delete
 * this class and let extensions/shopee/ShopeeExtension.php register itself the
 * same way the other marketplace extensions do.
 */
class ShopeeIntegration implements IntegrationProvider
{
    public function integrationId(): string
    {
        // "core:" prefix tells IntegrationRegistry to skip extension/license gates
        // since Shopee currently has no manifest. Permission gating still applies.
        return 'core:shopee';
    }

    public function integrationCards(): array
    {
        return [
            new IntegrationCard(
                id: 'shopee',
                name: 'Shopee',
                tagline: 'Shopee marketplace integration — OAuth, products, orders, returns.',
                icon: 'shopee',
                accent: '#ee4d2d',
                permission: 'manage_shopee',
                menu: [
                    new MenuItem('Settings', 'shopee.index', 'manage_shopee'),
                    new MenuItem('Categories', 'shopee.categories.index', 'manage_shopee'),
                    new MenuItem('Product Groups', 'shopee.product-groups.index', 'manage_shopee'),
                    new MenuItem('Orders', 'shopee.orders.index', 'manage_shopee_orders'),
                ],
            ),
        ];
    }

    public function orderTabs(): array
    {
        return [
            new OrderTab(
                id: 'shopee',
                label: 'Shopee',
                icon: 'shopee',
                accent: '#ee4d2d',
                routeName: 'shopee.orders.index',
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
                        url: route('shopee.orders.show', $o->order_sn),
                    ))->all();
                },
            ),
        ];
    }
}
