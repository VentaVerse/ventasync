<?php

namespace App\Http\Controllers;

use App\Integrations\IntegrationRegistry;
use App\Models\Catalog\Order;
use App\Models\Catalog\Product;
use App\Models\Catalog\Category;
use App\Models\Catalog\Manufacturer;
use App\Models\Catalog\OrderStatus;
use App\Support\StoreKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
    private const RANGES = [
        '30d' => [
            'label' => '30d',
            'title' => 'Sales over the last 30 days',
            'heading' => 'the last 30 days',
            'group' => 'day',
        ],
        '90d' => [
            'label' => '90d',
            'title' => 'Sales over the last 90 days',
            'heading' => 'the last 90 days',
            'group' => 'day',
        ],
        'mtd' => [
            'label' => 'This Month',
            'title' => 'Sales this month',
            'heading' => 'this month',
            'group' => 'day',
        ],
        'lastmonth' => [
            'label' => 'Last Month',
            'title' => 'Sales last month',
            'heading' => 'last month',
            'group' => 'day',
        ],
        'ytd' => [
            'label' => 'This Year',
            'title' => 'Sales this year',
            'heading' => 'this year',
            'group' => 'month',
        ],
        '12m' => [
            'label' => '12m',
            'title' => 'Sales over the last 12 months',
            'heading' => 'the last 12 months',
            'group' => 'month',
        ],
        'all' => [
            'label' => 'All Time',
            'title' => 'Sales, all time',
            'heading' => 'all time',
            'group' => 'month',
        ],
    ];

    private ?\Carbon\Carbon $firstOrderMonth = null;

    public function index(Request $request)
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $range = array_key_exists((string) $request->query('range'), self::RANGES)
            ? (string) $request->query('range')
            : '30d';

        $storeOptions = app(IntegrationRegistry::class)->visibleStoreOptions($request->user());
        $storeKey = (string) $request->query('store', '');
        if (! in_array($storeKey, array_column($storeOptions, 'key'), true)) {
            $storeKey = '';
        }

        $totalOrders = $this->orders($storeKey)->count();
        $revenueStatusIds = OrderStatus::where('add_revenue', 1)->pluck('order_status_id');
        $totalRevenue = (float) $this->orders($storeKey)->whereIn('order_status_id', $revenueStatusIds)->sum('total');

        $ordersBySource = $this->orders($storeKey)
            ->select(
                'marketplace_source',
                DB::raw('COUNT(*) as cnt'),
                DB::raw('SUM(CASE WHEN order_status_id IN (' . ($revenueStatusIds->isNotEmpty() ? $revenueStatusIds->implode(',') : '0') . ') THEN total ELSE 0 END) as rev')
            )
            ->groupBy('marketplace_source')
            ->get()
            ->keyBy(fn ($r) => $r->marketplace_source ?: 'direct');

        $todayStart = now()->startOfDay();
        $rolling7 = now()->startOfWeek(\Carbon\Carbon::MONDAY);
        $yesterdayStart = now()->subDay()->startOfDay();
        $yesterdayEnd = now()->startOfDay();
        $lastWeek7 = now()->subWeek()->startOfWeek(\Carbon\Carbon::MONDAY);
        $lastWeekEnd = $rolling7;

        $todayOrders = $this->orders($storeKey)->where('date_added', '>=', $todayStart)->count();
        $todayRevenue = (float) $this->orders($storeKey)->where('date_added', '>=', $todayStart)->whereIn('order_status_id', $revenueStatusIds)->sum('total');
        $yesterdayOrders = $this->orders($storeKey)->where('date_added', '>=', $yesterdayStart)->where('date_added', '<', $yesterdayEnd)->count();
        $yesterdayRevenue = (float) $this->orders($storeKey)->where('date_added', '>=', $yesterdayStart)->where('date_added', '<', $yesterdayEnd)->whereIn('order_status_id', $revenueStatusIds)->sum('total');
        $weekOrders = $this->orders($storeKey)->where('date_added', '>=', $rolling7)->count();
        $weekRevenue = (float) $this->orders($storeKey)->where('date_added', '>=', $rolling7)->whereIn('order_status_id', $revenueStatusIds)->sum('total');
        $lastWeekOrders = $this->orders($storeKey)->where('date_added', '>=', $lastWeek7)->where('date_added', '<', $lastWeekEnd)->count();
        $lastWeekRevenue = (float) $this->orders($storeKey)->where('date_added', '>=', $lastWeek7)->where('date_added', '<', $lastWeekEnd)->whereIn('order_status_id', $revenueStatusIds)->sum('total');

        $monthStart = now()->startOfMonth();
        $lastMonthStart = now()->subMonthNoOverflow()->startOfMonth();
        $lastMonthEnd = $monthStart;
        $monthOrders = $this->orders($storeKey)->where('date_added', '>=', $monthStart)->count();
        $monthRevenue = (float) $this->orders($storeKey)->where('date_added', '>=', $monthStart)->whereIn('order_status_id', $revenueStatusIds)->sum('total');
        $lastMonthOrders = $this->orders($storeKey)->where('date_added', '>=', $lastMonthStart)->where('date_added', '<', $lastMonthEnd)->count();
        $lastMonthRevenue = (float) $this->orders($storeKey)->where('date_added', '>=', $lastMonthStart)->where('date_added', '<', $lastMonthEnd)->whereIn('order_status_id', $revenueStatusIds)->sum('total');

        $chart = $this->buildChartData($range, $storeKey);
        $chartLabels = $chart['labels'];
        $chartRevenue = $chart['revenue'];
        $chartOrders = $chart['orders'];

        $platformSlices = $this->buildPlatformData($range, $storeKey);

        $recentOrders = $this->orders($storeKey, 'o')
            ->leftJoin($pfx . 'order_status as os', function ($j) use ($langId) {
                $j->on('o.order_status_id', '=', 'os.order_status_id')
                    ->where('os.language_id', '=', $langId);
            })
            ->orderByDesc('o.date_added')
            ->limit(10)
            ->get(['o.order_id', 'o.firstname', 'o.lastname', 'o.total', 'o.foreign_total', 'o.currency_code', 'o.date_added', 'os.name as status_name', 'o.marketplace_source']);

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
            ->selectRaw('COUNT(*) as products, SUM(quantity * cost) as at_cost, SUM(quantity * price) as at_sale')
            ->first();
        $inventoryProducts = (int) ($invRow->products ?? 0);
        $inventoryAtCost = (float) ($invRow->at_cost ?? 0);
        $inventoryAtSale = (float) ($invRow->at_sale ?? 0);
        $inventoryMargin = $inventoryAtSale > 0 ? round(($inventoryAtSale - $inventoryAtCost) / $inventoryAtSale * 100, 1) : 0;

        $registry = app(IntegrationRegistry::class);

        $merged = ['channelSyncStatuses' => [], 'channelPending' => []];
        foreach ($registry->dashboardContributors() as $contributor) {
            $data = $contributor->dashboardData();
            foreach (array_keys($merged) as $mapKey) {
                $merged[$mapKey] = array_merge($merged[$mapKey], (array) ($data[$mapKey] ?? []));
                unset($data[$mapKey]);
            }
            $extensionDashboardData = array_merge($extensionDashboardData, $data);
        }
        $extensionDashboardData = array_merge($extensionDashboardData, $merged);

        $sourceLabelsMap = [];
        foreach ($registry->availableMarketplaceSourceOptions() as $opt) {
            $sourceLabelsMap[$opt['value']] = $opt;
        }

        $hour = (int) now()->format('H');
        if ($hour < 12) {
            $greeting = 'Good morning';
        } elseif ($hour < 18) {
            $greeting = 'Good afternoon';
        } else {
            $greeting = 'Good evening';
        }


        $payoutScope = null;
        if ($storeKey !== '' && ($parsed = \App\Support\StoreKey::parse($storeKey)) !== null) {
            $payoutScope = [$parsed['source'] => [$parsed['store_id']]];
        }
        try {
            $payoutCards = app(\App\Services\Payouts\PayoutOverview::class)->cards($request->user(), $payoutScope);
        } catch (\Throwable $e) {
            report($e);
            $payoutCards = [];
        }

        return view('dashboard.index', array_merge([
            'payoutCards' => $payoutCards,
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
            'ranges' => self::RANGES,
            'storeKey' => $storeKey,
            'storeOptions' => $storeOptions,
            'recentOrders' => $recentOrders,
            'greeting' => $greeting,
            'sourceLabelsMap' => $sourceLabelsMap,
            'inventoryProducts' => $inventoryProducts,
            'inventoryAtCost' => $inventoryAtCost,
            'inventoryAtSale' => $inventoryAtSale,
            'inventoryMargin' => $inventoryMargin,
        ], $extensionDashboardData));
    }

    private function orders(string $storeKey, string $alias = ''): \Illuminate\Database\Query\Builder
    {
        $table = (string) config('catalog.prefix') . 'order';
        $q = DB::table($alias !== '' ? $table . ' as ' . $alias : $table);
        if ($storeKey !== '') {
            StoreKey::apply($q, [$storeKey], $alias !== '' ? $alias : $table);
        }

        return $q;
    }

    private function buildPlatformData(string $range, string $storeKey = ''): array
    {
        $pfx = (string) config('catalog.prefix');
        $revIds = OrderStatus::where('add_revenue', 1)->pluck('order_status_id');

        $window = $this->rangeWindow($range);

        $rows = $this->orders($storeKey)
            ->whereBetween('date_added', [$window['start'], $window['end']])
            ->select(
                'marketplace_source',
                'store_id',
                'store_name',
                DB::raw('COUNT(*) as orders'),
                DB::raw('MAX(date_added) as last_order'),
                DB::raw('SUM(CASE WHEN order_status_id IN (' . ($revIds->isNotEmpty() ? $revIds->implode(',') : '0') . ') THEN total ELSE 0 END) as revenue')
            )
            ->groupBy('marketplace_source', 'store_id', 'store_name')
            ->get();

        $liveNames = [];
        foreach (app(IntegrationRegistry::class)->visibleStoreOptions(auth()->user()) as $opt) {
            if (($opt['store_id'] ?? 0) > 0 && ($opt['source'] ?? '') !== '') {
                $liveNames[$opt['source'] . ':' . (int) $opt['store_id']] = (string) $opt['label'];
            }
        }

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
                $label = ucfirst($src);
            }

            $storeId = (int) $row->store_id;
            $stamped = trim((string) $row->store_name);

            $live = $storeId > 0 ? ($liveNames[explode(':', $src)[0] . ':' . $storeId] ?? null) : null;
            $store = $live ?? $stamped;
            if ($store === '' && $storeId > 0 && ! str_contains($src, ':')) {
                $store = 'Store #' . $storeId;
            }
            $key = $storeId > 0 ? $label . '|#' . $storeId : $label . '|';

            if (isset($slices[$key])) {
                $slices[$key]['orders'] += (int) $row->orders;
                $slices[$key]['revenue'] += round((float) $row->revenue, 2);
                if ($live === null && $store !== '' && (string) $row->last_order > (string) $slices[$key]['last_order']) {
                    $slices[$key]['store'] = $store;
                    $slices[$key]['name'] = \App\Support\StoreLabel::of($label, $store);
                    $slices[$key]['last_order'] = (string) $row->last_order;
                }
                continue;
            }

            $slices[$key] = [
                'label'   => $label,
                'name'    => \App\Support\StoreLabel::of($label, $store),
                'store'   => $store,
                'orders'  => (int) $row->orders,
                'revenue' => round((float) $row->revenue, 2),
                'last_order' => (string) $row->last_order,
            ];
        }

        $slices = array_values($slices);
        usort($slices, fn ($a, $b) => $b['orders'] <=> $a['orders']);

        return $slices;
    }

    private function rangeWindow(string $range): array
    {
        $now = now();

        return match ($range) {
            '90d' => [
                'start' => $now->copy()->subDays(89)->startOfDay(),
                'end' => $now->copy()->endOfDay(),
            ],
            'mtd' => [
                'start' => $now->copy()->startOfMonth(),
                'end' => $now->copy()->endOfDay(),
            ],
            'lastmonth' => [
                'start' => $now->copy()->subMonth()->startOfMonth(),
                'end' => $now->copy()->subMonth()->endOfMonth(),
            ],
            'ytd' => [
                'start' => $now->copy()->startOfYear(),
                'end' => $now->copy()->endOfDay(),
            ],
            '12m' => [
                'start' => $now->copy()->subMonths(11)->startOfMonth(),
                'end' => $now->copy()->endOfDay(),
            ],
            'all' => [
                'start' => $this->firstOrderMonth(),
                'end' => $now->copy()->endOfDay(),
            ],
            default => [
                'start' => $now->copy()->subDays(29)->startOfDay(),
                'end' => $now->copy()->endOfDay(),
            ],
        };
    }

    private function firstOrderMonth(): \Carbon\Carbon
    {
        if ($this->firstOrderMonth !== null) {
            return $this->firstOrderMonth->copy();
        }

        $first = DB::table((string) config('catalog.prefix') . 'order')->min('date_added');

        $this->firstOrderMonth = $first
            ? \Carbon\Carbon::parse($first)->startOfMonth()
            : now()->startOfMonth();

        return $this->firstOrderMonth->copy();
    }

    private function buildChartData(string $range, string $storeKey = ''): array
    {
        $pfx = (string) config('catalog.prefix');
        $revIds = OrderStatus::where('add_revenue', 1)->pluck('order_status_id');
        $revCase = 'SUM(CASE WHEN order_status_id IN (' . ($revIds->isNotEmpty() ? $revIds->implode(',') : '0') . ') THEN total ELSE 0 END) as revenue';

        $window = $this->rangeWindow($range);
        $byMonth = (self::RANGES[$range]['group'] ?? 'day') === 'month';

        $periodSql = $byMonth
            ? "DATE_FORMAT(date_added, '%Y-%m')"
            : 'DATE(date_added)';

        $rows = $this->orders($storeKey)
            ->select(DB::raw($periodSql . ' as period'), DB::raw($revCase), DB::raw('COUNT(*) as orders'))
            ->whereBetween('date_added', [$window['start'], $window['end']])
            ->groupBy(DB::raw($periodSql))
            ->orderBy('period')
            ->get()
            ->keyBy('period');

        $labels = [];
        $revenue = [];
        $orders = [];

        $maxBuckets = 400;

        $cursor = $window['start']->copy();
        while ($cursor->lessThanOrEqualTo($window['end']) && count($labels) < $maxBuckets) {
            $labels[] = $byMonth ? $cursor->format('M Y') : $cursor->format('M d');

            $key = $byMonth ? $cursor->format('Y-m') : $cursor->format('Y-m-d');
            $revenue[] = round((float) ($rows->get($key)->revenue ?? 0), 2);
            $orders[] = (int) ($rows->get($key)->orders ?? 0);

            $byMonth ? $cursor->addMonth() : $cursor->addDay();
        }

        return [
            'labels'  => $labels,
            'revenue' => $revenue,
            'orders'  => $orders,
        ];
    }
}
