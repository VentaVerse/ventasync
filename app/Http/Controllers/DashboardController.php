<?php

namespace App\Http\Controllers;

use App\Integrations\IntegrationRegistry;
use App\Models\Catalog\Order;
use App\Models\Catalog\Product;
use App\Models\Catalog\Category;
use App\Models\Catalog\Manufacturer;
use App\Models\Catalog\OrderStatus;
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

        // --- Marketplace + back-office widgets via the registry ---
        // Each extension that implements DashboardContributor returns the
        // named keys this view expects (lazadaPending, shopeeSyncStatus,
        // openPos, etc.). Defaults below seed nulls/zeros so the view stays
        // happy when an extension is disabled or unlicensed.
        $extensionDashboardData = [
            'lazadaProducts' => 0,
            'lazadaPending' => 0,
            'lazadaSyncStatus' => null,
            'shopeePending' => 0,
            'shopeeSyncStatus' => null,
            'tiktokPending' => 0,
            'tiktokSyncStatus' => null,
            'syncStatuses' => collect(),
            'openPos' => 0,
            'pendingDelivery' => 0,
            'overduePos' => 0,
            'monthlyPoSpend' => 0,
        ];

        $registry = app(IntegrationRegistry::class);

        foreach ($registry->dashboardContributors() as $contributor) {
            $extensionDashboardData = array_merge($extensionDashboardData, $contributor->dashboardData());
        }

        // Marketplace source labels for the recent-orders source column.
        // Same registry-driven map used by the Orders index — core has no
        // marketplace-specific knowledge.
        $sourceLabelsMap = [];
        foreach ($registry->availableMarketplaceSourceOptions() as $opt) {
            $sourceLabelsMap[$opt['value']] = $opt;
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


        return view('dashboard.index', array_merge([
            'totalProducts' => $totalProducts,
            'totalCategories' => $totalCategories,
            'totalManufacturers' => $totalManufacturers,
            'totalOrders' => $totalOrders,
            'totalRevenue' => $totalRevenue,
            'ordersBySource' => $ordersBySource,
            'todayOrders' => $todayOrders,
            'todayRevenue' => $todayRevenue,
            'weekOrders' => $weekOrders,
            'chartLabels' => $chartLabels,
            'chartRevenue' => $chartRevenue,
            'chartOrders' => $chartOrders,
            'recentOrders' => $recentOrders,
            'greeting' => $greeting,
            'sourceLabelsMap' => $sourceLabelsMap,
        ], $extensionDashboardData));
    }

    /**
     * AJAX endpoint for switching chart range.
     */
    public function chartData(Request $request)
    {
        $range = $request->query('range', '30d');
        return response()->json($this->buildChartData($range));
    }

    /**
     * AJAX endpoint for platform distribution pie chart.
     */
    public function platformData(Request $request)
    {
        $range = $request->query('range', '30d');
        $pfx = (string) config('catalog.prefix');
        $revIds = OrderStatus::where('add_revenue', 1)->pluck('order_status_id');

        $query = DB::table($pfx . 'order');

        if ($range === 'yearly') {
            $query->where('date_added', '>=', now()->startOfYear());
        } elseif ($range === 'monthly') {
            $query->where('date_added', '>=', now()->subDays(29)->startOfDay());
        }
        // '30d' also uses last 30 days (same as monthly for this chart)
        if ($range === '30d') {
            $query->where('date_added', '>=', now()->subDays(29)->startOfDay());
        }

        $rows = $query->select(
                'marketplace_source',
                DB::raw('COUNT(*) as orders'),
                DB::raw('SUM(CASE WHEN order_status_id IN (' . ($revIds->isNotEmpty() ? $revIds->implode(',') : '0') . ') THEN total ELSE 0 END) as revenue')
            )
            ->groupBy('marketplace_source')
            ->get();

        // Source options come from the integration registry — each marketplace
        // extension declares its own label + chart color via
        // MarketplaceSourceOptionsProvider::availableSourceOptions(). Core has
        // zero marketplace-specific knowledge here.
        $sourceOptionsMap = [];
        foreach (app(IntegrationRegistry::class)->availableMarketplaceSourceOptions() as $opt) {
            $sourceOptionsMap[$opt['value']] = $opt;
        }

        $slices = [];
        foreach ($rows as $row) {
            $src = trim((string) $row->marketplace_source);
            if ($src === '') {
                $label = 'Direct';
                $color = '#3b82f6';
            } elseif (isset($sourceOptionsMap[$src])) {
                $label = $sourceOptionsMap[$src]['label'];
                $color = $sourceOptionsMap[$src]['chart_color'] ?? '#64748b';
            } else {
                // Orphaned source (legacy bare 'opencart' before multi-store, or
                // a marketplace whose extension is disabled). Show the raw
                // identifier so it's still inspectable from the chart.
                $label = ucfirst($src);
                $color = '#64748b';
            }

            // Merge if same label exists (e.g. legacy 'opencart' + 'opencart:1')
            $found = false;
            foreach ($slices as &$s) {
                if ($s['label'] === $label) {
                    $s['orders'] += (int) $row->orders;
                    $s['revenue'] += round((float) $row->revenue, 2);
                    $found = true;
                    break;
                }
            }
            unset($s);
            if (!$found) {
                $slices[] = [
                    'label'   => $label,
                    'orders'  => (int) $row->orders,
                    'revenue' => round((float) $row->revenue, 2),
                    'color'   => $color,
                ];
            }
        }

        // Sort by orders descending
        usort($slices, fn ($a, $b) => $b['orders'] - $a['orders']);

        return response()->json(['slices' => $slices]);
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
