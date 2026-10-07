<?php

namespace App\Support;

use App\Models\Catalog\OrderStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ChannelMetrics
{
    private const WINDOW = 30;

    public function trend(string $source): ?array
    {
        $pfx = (string) config('catalog.prefix');
        $revCase = $this->revenueCase();

        $start = Carbon::now()->subDays(self::WINDOW - 1)->startOfDay();
        $prevStart = (clone $start)->subDays(self::WINDOW);

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

    private function revenueStatusIds()
    {
        $ids = OrderStatus::where('add_revenue', 1)->pluck('order_status_id');

        return $ids->isNotEmpty() ? $ids : collect([0]);
    }

    private function revenueCase(): string
    {
        return 'SUM(CASE WHEN order_status_id IN ('
            . $this->revenueStatusIds()->implode(',')
            . ') THEN total ELSE 0 END) as revenue';
    }
}
