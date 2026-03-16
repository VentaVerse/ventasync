<?php

namespace App\Http\Controllers;

use App\Models\Catalog\Order;
use App\Models\Catalog\Product;
use App\Models\Catalog\Category;
use App\Models\Catalog\Manufacturer;
use App\Models\Catalog\OrderStatus;
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
                ->whereIn('status', ['pending', 'repacked'])
                ->count();
        }

        // Shopee pending
        $shopeePending = 0;
        if (Schema::hasTable('shopee_orders')) {
            $shopeePending = DB::table('shopee_orders')
                ->whereIn('status', ['READY_TO_SHIP'])
                ->count();
        }

        // OpenCart pending (Pending + Processing order statuses)
        $pendingStatusIds = OrderStatus::whereIn('name', ['Pending', 'Processing'])->pluck('order_status_id');
        $pendingByStore = [];
        if ($pendingStatusIds->isNotEmpty()) {
            $pendingRows = DB::table($pfx . 'order')
                ->select('marketplace_source', DB::raw('COUNT(*) as cnt'))
                ->whereIn('order_status_id', $pendingStatusIds)
                ->where('marketplace_source', 'like', 'opencart:%')
                ->groupBy('marketplace_source')
                ->get();
            foreach ($pendingRows as $row) {
                $src = (string) $row->marketplace_source;
                if (str_starts_with($src, 'opencart:')) {
                    $storeId = (int) str_replace('opencart:', '', $src);
                    if ($storeId > 0) {
                        $pendingByStore[$storeId] = (int) $row->cnt;
                    }
                }
            }
        }

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

        // OpenCart store names for display
        $ocStoreNames = Schema::hasTable('opencart_settings')
            ? DB::table('opencart_settings')->pluck('store_name', 'id')->all()
            : [];

        // --- OpenCart sync status per store ---
        if (class_exists(\Extensions\opencart\Models\OpenCartSyncLog::class) && Schema::hasTable('opencart_settings')) {
            $syncStatuses = DB::table('opencart_settings as s')
                ->where('s.enabled', true)
                ->get(['s.id', 's.store_name', 's.last_order_sync_at', 's.last_product_sync_at'])
                ->map(function ($store) {
                    $lastLog = \Extensions\opencart\Models\OpenCartSyncLog::where('opencart_setting_id', $store->id)
                        ->orderByDesc('id')
                        ->first(['status', 'entity_type', 'completed_at', 'error_message']);
                    $store->last_status = $lastLog->status ?? null;
                    $store->last_entity = $lastLog->entity_type ?? null;
                    $store->last_completed = $lastLog->completed_at ?? null;
                    $store->last_error = $lastLog->error_message ?? null;

                    // Last successful push qty timestamp
                    $lastPushQty = \Extensions\opencart\Models\OpenCartSyncLog::where('opencart_setting_id', $store->id)
                        ->where('entity_type', 'product_qty')
                        ->where('direction', 'push')
                        ->where('status', 'completed')
                        ->orderByDesc('id')
                        ->value('completed_at');
                    $store->last_push_qty_at = $lastPushQty;

                    return $store;
                });
            $syncStatuses = $syncStatuses->map(function ($store) use ($pendingByStore) {
                $store->pending_count = $pendingByStore[$store->id] ?? 0;
                return $store;
            });
        } else {
            $syncStatuses = collect();
        }

        // --- Lazada sync status (from settings table, independent of API logging) ---
        $lazadaSyncStatus = null;
        if (Schema::hasTable('lazada_settings')) {
            $lzSetting = \App\Models\LazadaSetting::query()->first();
            if ($lzSetting) {
                $lazadaSyncStatus = (object) [
                    'order_sync_at' => $lzSetting->last_order_sync_at,
                    'push_stock_at' => $lzSetting->last_stock_push_at,
                    'return_sync_at' => $lzSetting->last_return_sync_at,
                    'expires_at'    => $lzSetting->expires_at,
                ];
            }
        }

        // --- Shopee sync status (from settings table, independent of API logging) ---
        $shopeeSyncStatus = null;
        if (Schema::hasTable('shopee_settings')) {
            $spSetting = \App\Models\ShopeeSetting::query()->first();
            if ($spSetting) {
                $shopeeSyncStatus = (object) [
                    'order_sync_at' => $spSetting->last_order_sync_at,
                    'push_stock_at' => $spSetting->last_stock_push_at,
                    'return_sync_at' => $spSetting->last_return_sync_at,
                    'expires_at'    => $spSetting->expires_at,
                ];
            }
        }

        // --- TikTok sync status ---
        $tiktokSyncStatus = null;
        $tiktokPending = 0;
        if (class_exists(\Extensions\tiktok\Models\TikTokSetting::class) && Schema::hasTable('tiktok_settings')) {
            $ttSetting = \Extensions\tiktok\Models\TikTokSetting::query()->first();
            if ($ttSetting) {
                $tiktokSyncStatus = (object) [
                    'order_sync_at'  => $ttSetting->last_order_sync_at,
                    'push_stock_at'  => $ttSetting->last_stock_push_at,
                    'expires_at'     => $ttSetting->expires_at,
                ];
            }
            if (Schema::hasTable('tiktok_orders')) {
                $tiktokPending = DB::table('tiktok_orders')
                    ->where('status', 'AWAITING_SHIPMENT')
                    ->count();
            }
        }

        // --- Purchase Order stats (from purchasing extension) ---
        $openPos = 0;
        $pendingDelivery = 0;
        $overduePos = 0;
        $monthlyPoSpend = 0;
        if (class_exists(\Extensions\purchasing\Models\PurchaseOrder::class)) {
            $openPos = \Extensions\purchasing\Models\PurchaseOrder::whereIn('status', ['draft', 'approved', 'ordered'])->count();
            $pendingDelivery = \Extensions\purchasing\Models\PurchaseOrder::whereIn('status', ['ordered', 'partially_received'])->count();
            $overduePos = \Extensions\purchasing\Models\PurchaseOrder::whereIn('status', ['ordered', 'partially_received'])
                ->where('expected_delivery_date', '<', now()->toDateString())->count();
            $monthlyPoSpend = (float) \Extensions\purchasing\Models\PurchaseOrder::where('status', 'received')
                ->whereMonth('received_at', now()->month)
                ->whereYear('received_at', now()->year)
                ->selectRaw('SUM(total * exchange_rate) as spend')
                ->value('spend');
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
            'chartLabels' => $chartLabels,
            'chartRevenue' => $chartRevenue,
            'chartOrders' => $chartOrders,
            'recentOrders' => $recentOrders,
            'ocStoreNames' => $ocStoreNames,
            'syncStatuses' => $syncStatuses,
            'lazadaSyncStatus' => $lazadaSyncStatus,
            'shopeeSyncStatus' => $shopeeSyncStatus,
            'tiktokSyncStatus' => $tiktokSyncStatus,
            'tiktokPending' => $tiktokPending,
            'openPos' => $openPos,
            'pendingDelivery' => $pendingDelivery,
            'overduePos' => $overduePos,
            'monthlyPoSpend' => $monthlyPoSpend,
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

        // Map OC store names
        $ocStores = Schema::hasTable('opencart_settings')
            ? DB::table('opencart_settings')->pluck('store_name', 'id')->toArray()
            : [];

        $slices = [];
        foreach ($rows as $row) {
            $src = trim((string) $row->marketplace_source);
            if ($src === 'shopee') {
                $label = 'Shopee';
                $color = '#ee4d2d';
            } elseif ($src === 'lazada') {
                $label = 'Lazada';
                $color = '#0F146D';
            } elseif ($src === 'tiktok') {
                $label = 'TikTok';
                $color = '#1e1e1e';
            } elseif (str_starts_with($src, 'opencart:')) {
                $storeId = (int) str_replace('opencart:', '', $src);
                $label = $ocStores[$storeId] ?? ('OpenCart #' . $storeId);
                // Fixed colors for known OC stores, random for new ones
                $ocFixedColors = [
                    1 => '#16a34a', // Shop A — green
                    2 => '#38bdf8', // Shop B — light blue
                ];
                $color = $ocFixedColors[$storeId] ?? sprintf('#%06X', mt_rand(0x333333, 0xCCCCCC));
            } else {
                $label = 'Direct';
                $color = '#3b82f6';
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
