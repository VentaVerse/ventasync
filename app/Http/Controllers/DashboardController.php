<?php

namespace App\Http\Controllers;

use App\Models\Catalog\Order;
use App\Models\Catalog\Product;
use App\Models\Catalog\Category;
use App\Models\Catalog\Manufacturer;
use App\Models\LazadaOrder;
use App\Models\LazadaProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
    public function index()
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        // --- Stat cards ---
        $totalProducts = DB::table($pfx . 'product')->count();
        $totalCategories = DB::table($pfx . 'category')->count();
        $totalManufacturers = DB::table($pfx . 'manufacturer')->count();

        $totalOrders = DB::table($pfx . 'order')->count();
        $totalRevenue = (float) DB::table($pfx . 'order')->sum('total');

        // Lazada stats
        $lazadaProducts = 0;
        $lazadaPending = 0;
        if (Schema::hasTable('lazada_products')) {
            $lazadaProducts = DB::table('lazada_products')->count();
        }
        if (Schema::hasTable('lazada_orders')) {
            $lazadaPending = DB::table('lazada_orders')
                ->whereIn('status', ['pending', 'packed', 'ready_to_ship'])
                ->count();
        }

        // --- Sales chart: default 30 days (loaded via JS) ---
        $chart = $this->buildChartData('30d');
        $chartLabels = $chart['labels'];
        $chartRevenue = $chart['revenue'];
        $chartOrders = $chart['orders'];

        // --- Recent orders (last 5) ---
        $recentOrders = DB::table($pfx . 'order as o')
            ->leftJoin($pfx . 'order_status as os', function ($j) use ($langId) {
                $j->on('o.order_status_id', '=', 'os.order_status_id')
                    ->where('os.language_id', '=', $langId);
            })
            ->orderByDesc('o.date_added')
            ->limit(5)
            ->get(['o.order_id', 'o.firstname', 'o.lastname', 'o.total', 'o.currency_code', 'o.date_added', 'os.name as status_name', 'o.marketplace_source']);

        return view('dashboard.index', [
            'totalProducts' => $totalProducts,
            'totalCategories' => $totalCategories,
            'totalManufacturers' => $totalManufacturers,
            'totalOrders' => $totalOrders,
            'totalRevenue' => $totalRevenue,
            'lazadaProducts' => $lazadaProducts,
            'lazadaPending' => $lazadaPending,
            'chartLabels' => $chartLabels,
            'chartRevenue' => $chartRevenue,
            'chartOrders' => $chartOrders,
            'recentOrders' => $recentOrders,
        ]);
    }

    /**
     * AJAX endpoint for switching chart range.
     */
    public function chartData(Request $request)
    {
        $range = $request->query('range', '30d');
        return response()->json($this->buildChartData($range));
    }

    private function buildChartData(string $range): array
    {
        $pfx = (string) config('catalog.prefix');

        if ($range === 'yearly') {
            // Last 5 years, grouped by year
            $startDate = now()->subYears(4)->startOfYear();

            $rows = DB::table($pfx . 'order')
                ->select(DB::raw('YEAR(date_added) as period'), DB::raw('SUM(total) as revenue'), DB::raw('COUNT(*) as orders'))
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
            // Last 12 months, grouped by month
            $startDate = now()->subMonths(11)->startOfMonth();

            $rows = DB::table($pfx . 'order')
                ->select(DB::raw("DATE_FORMAT(date_added, '%Y-%m') as period"), DB::raw('SUM(total) as revenue'), DB::raw('COUNT(*) as orders'))
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
            // Last 30 days, grouped by day
            $chartDays = 30;
            $startDate = now()->subDays($chartDays - 1)->startOfDay();

            $rows = DB::table($pfx . 'order')
                ->select(DB::raw('DATE(date_added) as period'), DB::raw('SUM(total) as revenue'), DB::raw('COUNT(*) as orders'))
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
}
