<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    private const CATEGORIES = [
        'all'          => ['label' => 'All',            'types' => []],
        'orders'       => ['label' => 'Orders',         'types' => ['Order']],
        'purchasing'   => ['label' => 'Purchasing',     'types' => ['PurchaseOrder']],
        'catalog'      => ['label' => 'Catalog',        'types' => ['Product']],
        'marketplace'  => ['label' => 'Marketplace',    'types' => ['Shopee Order', 'Shopee Profile', 'Lazada Order', 'Lazada Product']],
        'users'        => ['label' => 'Users',          'types' => ['User', 'User Group']],
        'system'       => ['label' => 'System',         'types' => ['Currency', 'Setting', 'Vendor', 'Category', 'Manufacturer', 'Option', 'OrderStatus']],
    ];

    public function index(Request $request)
    {
        $q        = trim((string) $request->get('q', ''));
        $action   = trim((string) $request->get('action', ''));
        $userId   = (int) $request->get('user_id', 0);
        $source   = trim((string) $request->get('source', ''));
        $dateFrom = trim((string) $request->get('date_from', ''));
        $dateTo   = trim((string) $request->get('date_to', ''));
        $category = trim((string) $request->get('category', 'all'));

        $query = ActivityLog::query()->orderByDesc('created_at');

        // Category filter
        if ($category !== 'all' && isset(self::CATEGORIES[$category])) {
            $types = self::CATEGORIES[$category]['types'];
            if (!empty($types)) {
                $query->whereIn('subject_type', $types);
            }
        }

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('subject_label', 'like', '%' . $q . '%')
                  ->orWhere('subject_type', 'like', '%' . $q . '%');
            });
        }

        if ($action !== '') {
            $query->where('action', $action);
        }

        if ($userId > 0) {
            $query->where('user_id', $userId);
        }

        if ($source !== '') {
            $query->where('source', $source);
        }

        if ($dateFrom !== '') {
            $query->where('created_at', '>=', $dateFrom . ' 00:00:00');
        }

        if ($dateTo !== '') {
            $query->where('created_at', '<=', $dateTo . ' 23:59:59');
        }

        $logs = $query->paginate(50)->withQueryString();

        $actions = ActivityLog::query()
            ->select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action');

        $users = User::orderBy('name')->get(['id', 'name']);

        $retentionDays = (int) (Setting::singleton()->activity_log_retention_days ?? 90);

        // Category counts
        $categoryCounts = [];
        $categoryCounts['all'] = ActivityLog::count();
        foreach (self::CATEGORIES as $key => $cat) {
            if ($key === 'all') continue;
            if (!empty($cat['types'])) {
                $categoryCounts[$key] = ActivityLog::whereIn('subject_type', $cat['types'])->count();
            }
        }

        $categories = self::CATEGORIES;

        return view('activity_log.index', compact('logs', 'q', 'action', 'userId', 'source', 'dateFrom', 'dateTo', 'actions', 'users', 'retentionDays', 'category', 'categories', 'categoryCounts'));
    }

    public function clear()
    {
        $days = (int) (Setting::singleton()->activity_log_retention_days ?? 90);

        ActivityLog::where('created_at', '<', now()->subDays($days))->delete();

        return redirect()->route('activity_log.index')
            ->with('status', "Logs older than {$days} days cleared.");
    }
}
