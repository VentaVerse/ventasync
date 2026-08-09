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
    /**
     * The three ranges the trend charts offer. Anything else in ?range=
     * falls back to the first: the value reaches SQL through
     * buildChartData()/buildPlatformData(), so it is whitelisted here
     * rather than validated at each use.
     */
    private const RANGES = ['30d', 'monthly', 'yearly'];

    public function index(Request $request)
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $range = in_array($request->query('range'), self::RANGES, true)
            ? (string) $request->query('range')
            : '30d';

        // --- Stat cards ---
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
        $rolling7 = now()->startOfWeek(\Carbon\Carbon::MONDAY);
        $yesterdayStart = now()->subDay()->startOfDay();
        $yesterdayEnd = now()->startOfDay();
        $lastWeek7 = now()->subWeek()->startOfWeek(\Carbon\Carbon::MONDAY);
        $lastWeekEnd = $rolling7;

        $todayOrders = DB::table($pfx . 'order')->where('date_added', '>=', $todayStart)->count();
        $todayRevenue = (float) DB::table($pfx . 'order')->where('date_added', '>=', $todayStart)->whereIn('order_status_id', $revenueStatusIds)->sum('total');
        $yesterdayOrders = DB::table($pfx . 'order')->where('date_added', '>=', $yesterdayStart)->where('date_added', '<', $yesterdayEnd)->count();
        $yesterdayRevenue = (float) DB::table($pfx . 'order')->where('date_added', '>=', $yesterdayStart)->where('date_added', '<', $yesterdayEnd)->whereIn('order_status_id', $revenueStatusIds)->sum('total');
        $weekOrders = DB::table($pfx . 'order')->where('date_added', '>=', $rolling7)->count();
        $weekRevenue = (float) DB::table($pfx . 'order')->where('date_added', '>=', $rolling7)->whereIn('order_status_id', $revenueStatusIds)->sum('total');
        $lastWeekOrders = DB::table($pfx . 'order')->where('date_added', '>=', $lastWeek7)->where('date_added', '<', $lastWeekEnd)->count();
        $lastWeekRevenue = (float) DB::table($pfx . 'order')->where('date_added', '>=', $lastWeek7)->where('date_added', '<', $lastWeekEnd)->whereIn('order_status_id', $revenueStatusIds)->sum('total');

        $monthStart = now()->startOfMonth();
        $lastMonthStart = now()->subMonthNoOverflow()->startOfMonth();
        $lastMonthEnd = $monthStart;
        $monthOrders = DB::table($pfx . 'order')->where('date_added', '>=', $monthStart)->count();
        $monthRevenue = (float) DB::table($pfx . 'order')->where('date_added', '>=', $monthStart)->whereIn('order_status_id', $revenueStatusIds)->sum('total');
        $lastMonthOrders = DB::table($pfx . 'order')->where('date_added', '>=', $lastMonthStart)->where('date_added', '<', $lastMonthEnd)->count();
        $lastMonthRevenue = (float) DB::table($pfx . 'order')->where('date_added', '>=', $lastMonthStart)->where('date_added', '<', $lastMonthEnd)->whereIn('order_status_id', $revenueStatusIds)->sum('total');

        // --- Trend series, built for the requested range ---
        // Both trend charts and the platform ranking are rendered as inline
        // SVG by the view, so every series the page draws is resolved here,
        // in one request, rather than fetched back over AJAX after paint.
        $chart = $this->buildChartData($range);
        $chartLabels = $chart['labels'];
        $chartRevenue = $chart['revenue'];
        $chartOrders = $chart['orders'];

        $platformSlices = $this->buildPlatformData($range);

        // --- Recent orders (last 10) ---
        $recentOrders = DB::table($pfx . 'order as o')
            ->leftJoin($pfx . 'order_status as os', function ($j) use ($langId) {
                $j->on('o.order_status_id', '=', 'os.order_status_id')
                    ->where('os.language_id', '=', $langId);
            })
            ->orderByDesc('o.date_added')
            ->limit(10)
            ->get(['o.order_id', 'o.firstname', 'o.lastname', 'o.total', 'o.foreign_total', 'o.currency_code', 'o.date_added', 'os.name as status_name', 'o.marketplace_source']);

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

        $invRow = DB::table($pfx . 'product')
            ->where('status', 1)
            // COUNT rides along in the aggregate that was already running,
            // so the rail's heading costs no extra query.
            ->selectRaw('COUNT(*) as products, SUM(quantity * cost) as at_cost, SUM(quantity * price) as at_sale')
            ->first();
        $inventoryProducts = (int) ($invRow->products ?? 0);
        $inventoryAtCost = (float) ($invRow->at_cost ?? 0);
        $inventoryAtSale = (float) ($invRow->at_sale ?? 0);
        $inventoryMargin = $inventoryAtSale > 0 ? round(($inventoryAtSale - $inventoryAtCost) / $inventoryAtSale * 100, 1) : 0;

        $registry = app(IntegrationRegistry::class);

        foreach ($registry->dashboardContributors() as $contributor) {
            $extensionDashboardData = array_merge($extensionDashboardData, $contributor->dashboardData());
        }

        // Pending order count per marketplace_source, straight off the core
        // order table. Core owns that table and the two statuses, so this is
        // not marketplace-specific knowledge; it exists so a channel whose
        // extension keeps no order table of its own (Venta) still gets a
        // pending figure. It used to be a DB::table() query inside the Blade
        // view, which is a query in the presentation layer and re-ran once
        // per configured store.
        $pendingStatusIds = OrderStatus::whereIn('name', ['Pending', 'Processing'])->pluck('order_status_id');
        $pendingBySource = $pendingStatusIds->isEmpty()
            ? []
            : DB::table($pfx . 'order')
                ->select('marketplace_source', DB::raw('COUNT(*) as cnt'))
                ->whereIn('order_status_id', $pendingStatusIds)
                ->groupBy('marketplace_source')
                ->pluck('cnt', 'marketplace_source')
                ->map(fn ($c) => (int) $c)
                ->all();

        // Marketplace source labels for the recent-orders source column.
        // Same registry-driven map used by the Orders index - core has no
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
            'totalOrders' => $totalOrders,
            'totalRevenue' => $totalRevenue,
            'ordersBySource' => $ordersBySource,
            'todayOrders' => $todayOrders,
            'todayRevenue' => $todayRevenue,
            'yesterdayOrders' => $yesterdayOrders,
            'yesterdayRevenue' => $yesterdayRevenue,
            'weekOrders' => $weekOrders,
            'weekRevenue' => $weekRevenue,
            'lastWeekOrders' => $lastWeekOrders,
            'lastWeekRevenue' => $lastWeekRevenue,
            'monthOrders' => $monthOrders,
            'monthRevenue' => $monthRevenue,
            'lastMonthOrders' => $lastMonthOrders,
            'lastMonthRevenue' => $lastMonthRevenue,
            'chartLabels' => $chartLabels,
            'chartRevenue' => $chartRevenue,
            'chartOrders' => $chartOrders,
            'platformSlices' => $platformSlices,
            'range' => $range,
            'pendingBySource' => $pendingBySource,
            'recentOrders' => $recentOrders,
            'greeting' => $greeting,
            'sourceLabelsMap' => $sourceLabelsMap,
            'inventoryProducts' => $inventoryProducts,
            'inventoryAtCost' => $inventoryAtCost,
            'inventoryAtSale' => $inventoryAtSale,
            'inventoryMargin' => $inventoryMargin,
        ], $extensionDashboardData));
    }

    /**
     * The platform ranking behind "Orders by channel".
     *
     * Was an AJAX endpoint feeding a Chart.js doughnut. The doughnut is gone
     * (see the view), so this is a plain server-side builder now and the
     * page needs no second request to draw.
     *
     * The `chart_color` each marketplace extension declares through
     * MarketplaceSourceOptionsProvider is deliberately NOT read here. Those
     * seven brand hues fail as a categorical chart palette: Lazada's
     * #0f146d sits outside the usable lightness band and Shopify's #95bf47
     * contrasts 2.08:1 against the light surface, under the 3:1 floor for a
     * mark that carries meaning. The ranking draws every bar in one hue and
     * lets the written channel name carry identity instead, so no chart on
     * this page depends on telling seven brand colours apart. The key stays
     * on the extension contract for anything else that wants it.
     *
     * @return list<array{label:string, orders:int, revenue:float}>
     */
    private function buildPlatformData(string $range): array
    {
        $pfx = (string) config('catalog.prefix');
        $revIds = OrderStatus::where('add_revenue', 1)->pluck('order_status_id');

        $rows = DB::table($pfx . 'order')
            ->where('date_added', '>=', $this->rangeStart($range))
            ->select(
                'marketplace_source',
                DB::raw('COUNT(*) as orders'),
                DB::raw('SUM(CASE WHEN order_status_id IN (' . ($revIds->isNotEmpty() ? $revIds->implode(',') : '0') . ') THEN total ELSE 0 END) as revenue')
            )
            ->groupBy('marketplace_source')
            ->get();

        $sourceOptionsMap = [];
        foreach (app(IntegrationRegistry::class)->availableMarketplaceSourceOptions() as $opt) {
            $sourceOptionsMap[$opt['value']] = $opt;
        }

        $slices = [];
        foreach ($rows as $row) {
            $src = trim((string) $row->marketplace_source);

            if ($src === '') {
                $label = 'Direct';
            } elseif (isset($sourceOptionsMap[$src])) {
                $label = (string) $sourceOptionsMap[$src]['label'];
            } else {
                // Orphaned source (a legacy bare 'opencart' from before
                // multi-store, or a marketplace whose extension is off).
                // Show the raw identifier so it stays inspectable.
                $label = ucfirst($src);
            }

            // Merge same-label rows (legacy 'opencart' + 'opencart:1').
            if (isset($slices[$label])) {
                $slices[$label]['orders'] += (int) $row->orders;
                $slices[$label]['revenue'] += round((float) $row->revenue, 2);
                continue;
            }

            $slices[$label] = [
                'label'   => $label,
                'orders'  => (int) $row->orders,
                'revenue' => round((float) $row->revenue, 2),
            ];
        }

        $slices = array_values($slices);
        usort($slices, fn ($a, $b) => $b['orders'] <=> $a['orders']);

        return $slices;
    }

    /** First instant each range covers. One definition, three callers. */
    private function rangeStart(string $range): \Carbon\Carbon
    {
        return match ($range) {
            'yearly'  => now()->subYears(4)->startOfYear(),
            'monthly' => now()->subMonths(11)->startOfMonth(),
            default   => now()->subDays(29)->startOfDay(),
        };
    }

    private function buildChartData(string $range): array
    {
        $pfx = (string) config('catalog.prefix');
        $revIds = OrderStatus::where('add_revenue', 1)->pluck('order_status_id');
        $revCase = 'SUM(CASE WHEN order_status_id IN (' . ($revIds->isNotEmpty() ? $revIds->implode(',') : '0') . ') THEN total ELSE 0 END) as revenue';

        if ($range === 'yearly') {
            // Last 5 years, grouped by year
            $startDate = $this->rangeStart($range);

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
            $startDate = $this->rangeStart($range);

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
            $startDate = $this->rangeStart($range);

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
