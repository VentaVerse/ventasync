<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Catalog\OrderStatus;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
    public function index()
    {
        $langId = (int) config('catalog.default_language_id');

        $totalOrders = DB::table('orders')->count();
        $revenueStatusIds = OrderStatus::where('add_revenue', 1)->pluck('order_status_id');
        $totalRevenue = (float) DB::table('orders')->whereIn('order_status_id', $revenueStatusIds)->sum('total');

        $lazadaPending = 0;
        if (Schema::hasTable('ch_lazada_orders')) {
            $lazadaPending = DB::table('ch_lazada_orders')
                ->whereIn('status', ['pending', 'repacked'])
                ->count();
        }

        $shopeePending = 0;
        if (Schema::hasTable('ch_shopee_orders')) {
            $shopeePending = DB::table('ch_shopee_orders')
                ->whereIn('status', ['READY_TO_SHIP', 'PROCESSED', 'RETRY_SHIP'])
                ->count();
        }

        $pendingStatusIds = OrderStatus::where(function ($q) {
            $q->where('name', 'like', '%pending%')
              ->orWhere('name', 'like', '%processing%');
        })->pluck('order_status_id');

        $openCartStores = class_exists(\Extensions\OpenCart\Models\OpenCartStore::class)
            ? \Extensions\OpenCart\Models\OpenCartStore::where('enabled', true)->get(['id', 'store_name'])
            : collect();
        $storePending = [];
        foreach ($openCartStores as $store) {
            $count = DB::table('orders')
                ->where('channel', 'opencart:' . $store->id)
                ->whereIn('order_status_id', $pendingStatusIds)
                ->count();
            $storePending[] = [
                'name'  => $store->store_name,
                'count' => $count,
            ];
        }

        $chart = $this->buildChartData('30d');

        $recentOrders = DB::table('orders as o')
            ->leftJoin('order_statuses as os', function ($j) use ($langId) {
                $j->on('o.order_status_id', '=', 'os.order_status_id')
                    ->where('os.language_id', '=', $langId);
            })
            ->orderByDesc('o.date_added')
            ->limit(5)
            ->get([
                'o.order_id',
                'o.firstname',
                'o.lastname',
                'o.total',
                'o.foreign_total',
                'o.currency_code',
                'o.date_added',
                'os.name as status_name',
                'o.channel',
                'o.store_name',
            ]);

        $openCartStoreNames = $openCartStores->pluck('store_name', 'id')->toArray();
        $defaultCode = Money::defaultCode();

        return response()->json([
            'stats' => [
                'total_orders'    => $totalOrders,
                'total_revenue'   => round($totalRevenue, 2),
                'currency_code'   => $defaultCode,
                'lazada_pending'  => $lazadaPending,
                'shopee_pending'  => $shopeePending,
                'pending_by_channel' => ['lazada' => $lazadaPending, 'shopee' => $shopeePending],
                'store_pending'   => $storePending,
            ],
            'chart' => $chart,
            'recent_orders' => $recentOrders->map(fn ($o) => [
                'order_id'             => $o->order_id,
                'firstname'            => $o->firstname,
                'lastname'             => $o->lastname,
                'total'                => (float) $o->total,
                'currency_code'        => $defaultCode,
                'transaction_currency' => $o->foreign_total !== null ? $o->currency_code : null,
                'transaction_total'    => $o->foreign_total !== null ? (float) $o->foreign_total : null,
                'date_added'           => $o->date_added,
                'status_name'          => $o->status_name ?? '',
                'channel'              => self::resolveSourceLabel($o->channel ?? '', $openCartStoreNames),
                'store_name'           => (string) ($o->store_name ?? ''),
            ]),
        ]);
    }

    public function chartData(Request $request)
    {
        $range = $request->query('range', '30d');
        return response()->json($this->buildChartData($range));
    }

    private function buildChartData(string $range): array
    {
        $revIds = OrderStatus::where('add_revenue', 1)->pluck('order_status_id');
        $revCase = 'SUM(CASE WHEN order_status_id IN (' . ($revIds->isNotEmpty() ? $revIds->implode(',') : '0') . ') THEN total ELSE 0 END) as revenue';

        if ($range === 'yearly') {
            $startDate = now()->subYears(4)->startOfYear();

            $rows = DB::table('orders')
                ->select(DB::raw('YEAR(date_added) as period'), DB::raw($revCase), DB::raw('COUNT(*) as orders'))
                ->where('date_added', '>=', $startDate)
                ->groupBy(DB::raw('YEAR(date_added)'))
                ->orderBy('period')
                ->get()
                ->keyBy('period');

            $labels = [];
            $revenue = [];
            $orders = [];
            for ($i = 4; $i >= 0; $i--) {
                $year = now()->subYears($i)->format('Y');
                $labels[] = $year;
                $revenue[] = round((float) ($rows->get($year)->revenue ?? 0), 2);
                $orders[] = (int) ($rows->get($year)->orders ?? 0);
            }
        } elseif ($range === 'monthly') {
            $startDate = now()->subMonths(11)->startOfMonth();

            $rows = DB::table('orders')
                ->select(DB::raw("DATE_FORMAT(date_added, '%Y-%m') as period"), DB::raw($revCase), DB::raw('COUNT(*) as orders'))
                ->where('date_added', '>=', $startDate)
                ->groupBy(DB::raw("DATE_FORMAT(date_added, '%Y-%m')"))
                ->orderBy('period')
                ->get()
                ->keyBy('period');

            $labels = [];
            $revenue = [];
            $orders = [];
            for ($i = 11; $i >= 0; $i--) {
                $date = now()->subMonths($i);
                $key = $date->format('Y-m');
                $labels[] = $date->format('M Y');
                $revenue[] = round((float) ($rows->get($key)->revenue ?? 0), 2);
                $orders[] = (int) ($rows->get($key)->orders ?? 0);
            }
        } else {
            $chartDays = 30;
            $startDate = now()->subDays($chartDays - 1)->startOfDay();

            $rows = DB::table('orders')
                ->select(DB::raw('DATE(date_added) as period'), DB::raw($revCase), DB::raw('COUNT(*) as orders'))
                ->where('date_added', '>=', $startDate)
                ->groupBy(DB::raw('DATE(date_added)'))
                ->orderBy('period')
                ->get()
                ->keyBy('period');

            $labels = [];
            $revenue = [];
            $orders = [];
            for ($i = 0; $i < $chartDays; $i++) {
                $date = now()->subDays($chartDays - 1 - $i);
                $key = $date->format('Y-m-d');
                $labels[] = $date->format('M d');
                $revenue[] = round((float) ($rows->get($key)->revenue ?? 0), 2);
                $orders[] = (int) ($rows->get($key)->orders ?? 0);
            }
        }

        return [
            'labels'  => $labels,
            'revenue' => $revenue,
            'orders'  => $orders,
        ];
    }

    private static function resolveSourceLabel(string $source, array $openCartStoreNames): string
    {
        if (preg_match('/^opencart:(\d+)$/', $source, $m)) {
            return $openCartStoreNames[(int) $m[1]] ?? 'OpenCart';
        }

        return $source;
    }
}
