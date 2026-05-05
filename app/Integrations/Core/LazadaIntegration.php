<?php

namespace App\Integrations\Core;

use App\Integrations\Dto\RecentOrder;
use App\Integrations\Dto\TopProduct;
use App\Integrations\IntegrationCard;
use App\Integrations\MenuItem;
use App\Integrations\OrderTab;
use App\Integrations\SummaryProvider;
use App\Models\LazadaOrder;
use App\Models\LazadaSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TEMPORARY core integration provider for Lazada — see ShopeeIntegration for
 * the same migration plan. Delete this when Lazada moves to extensions/lazada/.
 */
class LazadaIntegration implements SummaryProvider
{
    public function integrationId(): string
    {
        return 'core:lazada';
    }

    public function integrationCard(): ?IntegrationCard
    {
        return new IntegrationCard(
            id: 'lazada',
            name: 'Lazada',
            tagline: 'Lazada marketplace integration — products, orders, returns.',
            icon: 'lazada',
            accent: '#0f146d',
            permission: 'manage_lazada',
            menu: [
                new MenuItem('Settings', 'lazada.index', 'manage_lazada'),
                new MenuItem('Product Groups', 'lazada.product-groups.index', 'manage_lazada'),
                new MenuItem('Orders', 'lazada.orders.index', 'manage_lazada_orders'),
            ],
        );
    }

    public function orderTab(): ?OrderTab
    {
        return new OrderTab(
            id: 'lazada',
            label: 'Lazada',
            icon: 'lazada',
            accent: '#0f146d',
            routeName: 'lazada.orders.index',
            permission: 'manage_lazada_orders',
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
        );
    }

    public function dailyOrdersCount(): int
    {
        if (!Schema::hasTable('lazada_orders')) {
            return 0;
        }
        return LazadaOrder::query()
            ->where('order_created_at', '>=', now()->startOfDay())
            ->count();
    }

    public function dailyRevenue(): float
    {
        if (!Schema::hasTable('lazada_orders') || !Schema::hasTable('lazada_order_products')) {
            return 0.0;
        }
        return (float) DB::table('lazada_order_products as p')
            ->join('lazada_orders as o', 'o.id', '=', 'p.lazada_order_id')
            ->where('o.order_created_at', '>=', now()->startOfDay())
            ->sum(DB::raw('p.quantity * COALESCE(p.paid_price, p.item_price)'));
    }

    public function topProductsToday(int $limit = 5): array
    {
        if (!Schema::hasTable('lazada_orders') || !Schema::hasTable('lazada_order_products')) {
            return [];
        }
        $rows = DB::table('lazada_order_products as p')
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
            ->get();

        return $rows->map(fn ($r) => new TopProduct(
            sku: (string) $r->sku,
            name: (string) ($r->name ?? $r->sku),
            imageUrl: $r->image ?: null,
            qtySold: (int) $r->qty_sold,
            revenue: (float) $r->revenue,
        ))->all();
    }

    public function recentOrders(int $limit = 5): array
    {
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
            url: route('lazada.orders.show', $o->order_id),
        ))->all();
    }
}
