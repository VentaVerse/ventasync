<?php

namespace App\Support;

use App\Models\Catalog\OrderStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sales figures for one channel, for that channel's own page.
 *
 * The Overview answers "how is this channel doing" and the main dashboard
 * answers "how do my channels compare". Both read the same table through the
 * same revenue definition, so the only difference between them is the scope:
 * everything here is filtered to a single marketplace_source and nothing here
 * knows another channel exists.
 *
 * The scope key is a store key ('shopee:1', 'venta:2'), the same key
 * ChannelWorkspace and the OrderTab counters use. It is NOT always the
 * marketplace_source: Venta and the store-based channels write the store
 * into the source ('venta:2'), but Shopee, Lazada and TikTok write the bare
 * channel ('shopee') with the store in store_id. App\Support\StoreKey is the
 * only parser, and StoreKey::apply() narrows an order query the right way
 * for both shapes. Filtering on marketplace_source alone read every big-3
 * store as "No sales recorded here yet" (the owner, 2026-09-06, on live).
 *
 * Revenue counts only statuses flagged add_revenue, matching
 * DashboardController exactly. A cancelled order is not income on either
 * screen.
 */
class ChannelMetrics
{
    /** Days in the trend window. Matches the dashboard's default range. */
    private const WINDOW = 30;

    /**
     * Orders and revenue per day for the last 30 days, plus the same totals
     * for the 30 days before that so the page can say whether this channel is
     * up or down rather than only what it did.
     *
     * Returns null when the channel has no orders in either window: a chart
     * of two flat zero lines is a worse answer than not drawing one, and the
     * page hides the panel instead.
     *
     * @return array{labels: string[], revenue: float[], orders: int[], totalRevenue: float, totalOrders: int, prevRevenue: float, prevOrders: int}|null
     */
    public function trend(string $source): ?array
    {
        $pfx = (string) config('catalog.prefix');
        $revCase = $this->revenueCase();

        $start = Carbon::now()->subDays(self::WINDOW - 1)->startOfDay();
        $prevStart = (clone $start)->subDays(self::WINDOW);

        // One query covers both windows. Reading the previous period
        // separately doubled a scan over 44k rows to answer a question the
        // same rows already contain.
        $rows = DB::table($pfx . 'order')
            ->selectRaw('DATE(date_added) as period')
            ->selectRaw($revCase)
            ->selectRaw('COUNT(*) as orders')
            ->tap(fn ($q) => StoreKey::apply($q, [$source], ''))
            ->where('date_added', '>=', $prevStart)
            ->groupBy(DB::raw('DATE(date_added)'))
            ->get()
            ->keyBy('period');

        $labels = [];
        $revenue = [];
        $orders = [];
        $totalRevenue = 0.0;
        $totalOrders = 0;

        for ($i = 0; $i < self::WINDOW; $i++) {
            $day = (clone $start)->addDays($i);
            $row = $rows->get($day->format('Y-m-d'));

            $dayRevenue = round((float) ($row->revenue ?? 0), 2);
            $dayOrders = (int) ($row->orders ?? 0);

            $labels[] = $day->format('M d');
            $revenue[] = $dayRevenue;
            $orders[] = $dayOrders;
            $totalRevenue += $dayRevenue;
            $totalOrders += $dayOrders;
        }

        $prevRevenue = 0.0;
        $prevOrders = 0;

        for ($i = 0; $i < self::WINDOW; $i++) {
            $day = (clone $prevStart)->addDays($i);
            $row = $rows->get($day->format('Y-m-d'));

            $prevRevenue += round((float) ($row->revenue ?? 0), 2);
            $prevOrders += (int) ($row->orders ?? 0);
        }

        if ($totalOrders === 0 && $prevOrders === 0) {
            return null;
        }

        return [
            'labels' => $labels,
            'revenue' => $revenue,
            'orders' => $orders,
            'totalRevenue' => round($totalRevenue, 2),
            'totalOrders' => $totalOrders,
            'prevRevenue' => round($prevRevenue, 2),
            'prevOrders' => $prevOrders,
        ];
    }

    /**
     * What actually sold on this channel over the same 30 days.
     *
     * Ranked by revenue rather than units, because a page whose job is
     * stocking decisions should lead with what the channel earns rather than
     * with whatever ships in the largest packs. Units are returned alongside,
     * since the two together are what makes a line readable.
     *
     * Grouped by product_id and not by name: the same product can be renamed
     * between orders, and grouping on the printed name would split one
     * product into two rows. The most recent name is the one shown.
     *
     * @return array<int, array{name: string, model: ?string, units: int, revenue: float}>
     */
    public function bestSellers(string $source, int $limit = 5): array
    {
        $pfx = (string) config('catalog.prefix');
        $start = Carbon::now()->subDays(self::WINDOW - 1)->startOfDay();
        $revIds = $this->revenueStatusIds();

        return DB::table($pfx . 'order_product as op')
            ->join($pfx . 'order as o', 'o.order_id', '=', 'op.order_id')
            ->selectRaw('op.product_id')
            ->selectRaw('MAX(op.name) as name')
            ->selectRaw('MAX(op.model) as model')
            ->selectRaw('SUM(op.quantity) as units')
            ->selectRaw('SUM(op.total) as revenue')
            ->tap(fn ($q) => StoreKey::apply($q, [$source], 'o'))
            ->where('o.date_added', '>=', $start)
            ->whereIn('o.order_status_id', $revIds)
            ->groupBy('op.product_id')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'name' => (string) $r->name,
                'model' => $r->model !== '' ? (string) $r->model : null,
                'units' => (int) $r->units,
                'revenue' => round((float) $r->revenue, 2),
            ])
            ->all();
    }

    /**
     * The statuses that count as income, as an id list safe to inline.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function revenueStatusIds()
    {
        $ids = OrderStatus::where('add_revenue', 1)->pluck('order_status_id');

        // An empty list would make whereIn match nothing, which is the
        // correct reading: no status earns revenue, so no order does.
        return $ids->isNotEmpty() ? $ids : collect([0]);
    }

    /**
     * Revenue is a conditional sum rather than a WHERE, so a day that took
     * only cancelled orders still reports its order count instead of
     * disappearing from the series.
     */
    private function revenueCase(): string
    {
        return 'SUM(CASE WHEN order_status_id IN ('
            . $this->revenueStatusIds()->implode(',')
            . ') THEN total ELSE 0 END) as revenue';
    }
}
