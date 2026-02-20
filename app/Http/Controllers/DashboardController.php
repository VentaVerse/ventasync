<?php

namespace App\Http\Controllers;

use App\Models\Catalog\Order;
use App\Models\Catalog\Product;
use App\Models\Catalog\Category;
use App\Models\Catalog\Manufacturer;
use App\Models\Catalog\OrderStatus;
use App\Models\LazadaOrder;
use App\Models\LazadaProduct;
use App\Models\LazadaApiLog;
use App\Models\OpenCartSyncLog;
use App\Models\ShopeeApiLog;
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
        $revenueStatusIds = OrderStatus::where('add_revenue', 1)->pluck('order_status_id');
        $totalRevenue = (float) DB::table($pfx . 'order')->whereIn('order_status_id', $revenueStatusIds)->sum('total');

        // --- Marketplace breakdown ---
        $ordersBySource = DB::table($pfx . 'order')
            ->select(
                'marketplace_source',
                DB::raw('COUNT(*) as cnt'),
                DB::raw('SUM(CASE WHEN order_status_id IN (' . ($revenueStatusIds->isNotEmpty() ? $revenueStatusIds->implode(',') : '0') . ') THEN total ELSE 0 END) as rev')
            )
            ->groupBy('marketplace_source')
            ->get()
            ->keyBy(fn ($r) => $r->marketplace_source ?: 'direct');

        // --- Today's snapshot ---
        $todayStart = now()->startOfDay();
        $weekStart = now()->startOfWeek();
        $todayOrders = DB::table($pfx . 'order')->where('date_added', '>=', $todayStart)->count();
        $todayRevenue = (float) DB::table($pfx . 'order')->where('date_added', '>=', $todayStart)->whereIn('order_status_id', $revenueStatusIds)->sum('total');
        $weekOrders = DB::table($pfx . 'order')->where('date_added', '>=', $weekStart)->count();

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

        // Shopee pending
        $shopeePending = 0;
        if (Schema::hasTable('shopee_orders')) {
            $shopeePending = DB::table('shopee_orders')
                ->whereIn('status', ['READY_TO_SHIP', 'PROCESSED'])
                ->count();
        }

        // OpenCart pending (Pending + Processing order statuses)
        $pendingStatusIds = OrderStatus::whereIn('name', ['Pending', 'Processing'])->pluck('order_status_id');
        $opencartPending = $pendingStatusIds->isNotEmpty()
            ? DB::table($pfx . 'order')->whereIn('order_status_id', $pendingStatusIds)->count()
            : 0;

        // --- Sales chart: default 30 days (loaded via JS) ---
        $chart = $this->buildChartData('30d');
        $chartLabels = $chart['labels'];
        $chartRevenue = $chart['revenue'];
        $chartOrders = $chart['orders'];

        // --- Recent orders (last 10) ---
        $recentOrders = DB::table($pfx . 'order as o')
            ->leftJoin($pfx . 'order_status as os', function ($j) use ($langId) {
                $j->on('o.order_status_id', '=', 'os.order_status_id')
                    ->where('os.language_id', '=', $langId);
            })
            ->orderByDesc('o.date_added')
            ->limit(10)
            ->get(['o.order_id', 'o.firstname', 'o.lastname', 'o.total', 'o.currency_code', 'o.date_added', 'os.name as status_name', 'o.marketplace_source']);

        // --- OpenCart sync status per store ---
        $syncStatuses = DB::table('opencart_settings as s')
            ->where('s.enabled', true)
            ->get(['s.id', 's.store_name', 's.last_order_sync_at'])
            ->map(function ($store) {
                $lastLog = OpenCartSyncLog::where('opencart_setting_id', $store->id)
                    ->orderByDesc('id')
                    ->first(['status', 'entity_type', 'completed_at', 'error_message']);
                $store->last_status = $lastLog->status ?? null;
                $store->last_entity = $lastLog->entity_type ?? null;
                $store->last_completed = $lastLog->completed_at ?? null;
                $store->last_error = $lastLog->error_message ?? null;
                return $store;
            });

        // --- Lazada sync status ---
        $lazadaSyncStatus = null;
        if (Schema::hasTable('lazada_api_logs')) {
            $lastOrderSync = LazadaApiLog::where('api_path', 'LIKE', '%/orders/get%')
                ->orderByDesc('id')->first(['created_at', 'ok']);
            $lastPushStock = LazadaApiLog::where('api_path', 'LIKE', '%/product/stock/sellable/update%')
                ->orderByDesc('id')->first(['created_at', 'ok']);
            $lazadaSyncStatus = (object) [
                'order_sync_at' => $lastOrderSync?->created_at,
                'order_sync_ok' => $lastOrderSync?->ok,
                'push_stock_at' => $lastPushStock?->created_at,
                'push_stock_ok' => $lastPushStock?->ok,
            ];
        }

        // --- Shopee sync status ---
        $shopeeSyncStatus = null;
        if (Schema::hasTable('shopee_api_logs')) {
            $lastOrderSync = ShopeeApiLog::where('api_path', 'LIKE', '%/order/get_order_list%')
                ->orderByDesc('id')->first(['created_at', 'ok']);
            $lastPushStock = ShopeeApiLog::where('api_path', 'LIKE', '%/product/update_stock%')
                ->orderByDesc('id')->first(['created_at', 'ok']);
            $lastReturnSync = ShopeeApiLog::where('api_path', 'LIKE', '%/returns/get_return_list%')
                ->orderByDesc('id')->first(['created_at', 'ok']);
            $shopeeSyncStatus = (object) [
                'order_sync_at' => $lastOrderSync?->created_at,
                'order_sync_ok' => $lastOrderSync?->ok,
                'push_stock_at' => $lastPushStock?->created_at,
                'push_stock_ok' => $lastPushStock?->ok,
                'return_sync_at' => $lastReturnSync?->created_at,
                'return_sync_ok' => $lastReturnSync?->ok,
            ];
        }

        // --- Greeting ---
        $hour = (int) now()->format('H');
        if ($hour < 12) {
            $greeting = 'Good morning';
        } elseif ($hour < 18) {
            $greeting = 'Good afternoon';
        } else {
            $greeting = 'Good evening';
        }


        return view('dashboard.index', [
            'totalProducts' => $totalProducts,
            'totalCategories' => $totalCategories,
            'totalManufacturers' => $totalManufacturers,
            'totalOrders' => $totalOrders,
            'totalRevenue' => $totalRevenue,
            'ordersBySource' => $ordersBySource,
            'todayOrders' => $todayOrders,
            'todayRevenue' => $todayRevenue,
            'weekOrders' => $weekOrders,
            'lazadaProducts' => $lazadaProducts,
            'lazadaPending' => $lazadaPending,
            'shopeePending' => $shopeePending,
            'opencartPending' => $opencartPending,
            'chartLabels' => $chartLabels,
            'chartRevenue' => $chartRevenue,
            'chartOrders' => $chartOrders,
            'recentOrders' => $recentOrders,
            'syncStatuses' => $syncStatuses,
            'lazadaSyncStatus' => $lazadaSyncStatus,
            'shopeeSyncStatus' => $shopeeSyncStatus,
            'greeting' => $greeting,
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
        $revIds = OrderStatus::where('add_revenue', 1)->pluck('order_status_id');
        $revCase = 'SUM(CASE WHEN order_status_id IN (' . ($revIds->isNotEmpty() ? $revIds->implode(',') : '0') . ') THEN total ELSE 0 END) as revenue';

        if ($range === 'yearly') {
            // Last 5 years, grouped by year
            $startDate = now()->subYears(4)->startOfYear();

            $rows = DB::table($pfx . 'order')
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
            // Last 12 months, grouped by month
            $startDate = now()->subMonths(11)->startOfMonth();

            $rows = DB::table($pfx . 'order')
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
            // Last 30 days, grouped by day
            $chartDays = 30;
            $startDate = now()->subDays($chartDays - 1)->startOfDay();

            $rows = DB::table($pfx . 'order')
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
}
