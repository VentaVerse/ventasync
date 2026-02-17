<?php

namespace App\Http\Controllers;

use App\Models\Catalog\Order;
use App\Models\Catalog\Product;
use App\Models\Catalog\Category;
use App\Models\Catalog\Manufacturer;
use App\Models\LazadaOrder;
use App\Models\LazadaProduct;
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

        // --- Sales chart: last 30 days ---
        $chartDays = 30;
        $startDate = now()->subDays($chartDays - 1)->startOfDay();

        $dailySales = DB::table($pfx . 'order')
            ->select(DB::raw('DATE(date_added) as day'), DB::raw('SUM(total) as revenue'), DB::raw('COUNT(*) as orders'))
            ->where('date_added', '>=', $startDate)
            ->groupBy(DB::raw('DATE(date_added)'))
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $chartLabels = [];
        $chartRevenue = [];
        $chartOrders = [];
        for ($i = 0; $i < $chartDays; $i++) {
            $day = now()->subDays($chartDays - 1 - $i)->format('Y-m-d');
            $chartLabels[] = now()->subDays($chartDays - 1 - $i)->format('M d');
            $chartRevenue[] = round((float) ($dailySales->get($day)->revenue ?? 0), 2);
            $chartOrders[] = (int) ($dailySales->get($day)->orders ?? 0);
        }

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
}
