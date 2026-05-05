<?php

namespace Extensions\tiktok;

use App\Extensions\ExtensionProvider;
use App\Integrations\Dto\RecentOrder;
use App\Integrations\Dto\TopProduct;
use App\Integrations\IntegrationCard;
use App\Integrations\IntegrationRegistry;
use App\Integrations\MenuItem;
use App\Integrations\OrderTab;
use App\Integrations\SummaryProvider;
use Extensions\tiktok\Models\TikTokOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TiktokExtension extends ExtensionProvider implements SummaryProvider
{
    protected string $id = 'tiktok';

    public function boot(): void
    {
        parent::boot();

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
            name: 'TikTok Shop',
            tagline: 'TikTok Shop integration — OAuth, product sync, orders.',
            icon: 'video',
            accent: '#fe2c55',
            permission: 'manage_tiktok',
            menu: [
                new MenuItem('Settings', 'ext.tiktok.index', 'manage_tiktok'),
                new MenuItem('Product Groups', 'ext.tiktok.product-groups.index', 'manage_tiktok'),
                new MenuItem('Orders', 'ext.tiktok.orders.index', 'manage_tiktok_orders'),
            ],
        );
    }

    public function orderTab(): ?OrderTab
    {
        return new OrderTab(
            id: $this->id,
            label: 'TikTok',
            icon: 'video',
            accent: '#fe2c55',
            routeName: 'ext.tiktok.orders.index',
            permission: 'manage_tiktok_orders',
            unprocessedCounter: function () {
                if (!Schema::hasTable('tiktok_orders')) {
                    return 0;
                }
                return TikTokOrder::query()->where('status', 'AWAITING_SHIPMENT')->count();
            },
        );
    }

    public function dailyOrdersCount(): int
    {
        if (!Schema::hasTable('tiktok_orders')) {
            return 0;
        }
        return TikTokOrder::query()
            ->where('order_created_at', '>=', now()->startOfDay())
            ->count();
    }

    public function dailyRevenue(): float
    {
        if (!Schema::hasTable('tiktok_orders') || !Schema::hasTable('tiktok_order_products')) {
            return 0.0;
        }
        return (float) DB::table('tiktok_order_products as p')
            ->join('tiktok_orders as o', 'o.id', '=', 'p.tiktok_order_id')
            ->where('o.order_created_at', '>=', now()->startOfDay())
            ->sum(DB::raw('p.quantity * COALESCE(p.sale_price, p.item_price)'));
    }

    public function topProductsToday(int $limit = 5): array
    {
        if (!Schema::hasTable('tiktok_orders') || !Schema::hasTable('tiktok_order_products')) {
            return [];
        }
        $rows = DB::table('tiktok_order_products as p')
            ->join('tiktok_orders as o', 'o.id', '=', 'p.tiktok_order_id')
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
                DB::raw('SUM(p.quantity * COALESCE(p.sale_price, p.item_price)) as revenue'),
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
        if (!Schema::hasTable('tiktok_orders')) {
            return [];
        }
        $orders = TikTokOrder::query()
            ->orderByDesc('order_created_at')
            ->limit($limit)
            ->get(['id', 'order_id', 'status', 'buyer_name', 'order_created_at']);

        $totals = DB::table('tiktok_order_products')
            ->whereIn('tiktok_order_id', $orders->pluck('id'))
            ->select('tiktok_order_id', DB::raw('SUM(quantity * COALESCE(sale_price, item_price)) as total'))
            ->groupBy('tiktok_order_id')
            ->pluck('total', 'tiktok_order_id');

        return $orders->map(fn ($o) => new RecentOrder(
            reference: (string) $o->order_id,
            customerName: $o->buyer_name,
            total: (float) ($totals[$o->id] ?? 0),
            statusLabel: (string) $o->status,
            orderedAt: $o->order_created_at,
            url: route('ext.tiktok.orders.show', $o->id),
        ))->all();
    }
}
