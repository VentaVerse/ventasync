<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Catalog\Order;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

class SearchController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->get('q', ''));

        if (strlen($q) < 2) {
            return response()->json(['results' => []]);
        }

        $results = [];

        // Orders
        $orders = Order::query()
            ->where(function ($sub) use ($q) {
                $sub->where('firstname', 'like', '%' . $q . '%')
                    ->orWhere('lastname', 'like', '%' . $q . '%')
                    ->orWhere('email', 'like', '%' . $q . '%')
                    ->orWhere('marketplace_order_id', 'like', '%' . $q . '%');
                if ((int) $q > 0) {
                    $sub->orWhere('order_id', '=', (int) $q);
                }
            })
            ->orderByDesc('order_id')
            ->limit(5)
            ->get(['order_id', 'firstname', 'lastname', 'email', 'marketplace_order_id', 'total']);

        foreach ($orders as $o) {
            $results[] = [
                'category' => 'Orders',
                'label' => '#' . $o->order_id . ' — ' . $o->firstname . ' ' . $o->lastname,
                'sub' => $o->marketplace_order_id ?: $o->email,
                'url' => route('orders.show', $o->order_id),
            ];
        }

        // Products
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $products = DB::table($pfx . 'product as p')
            ->join($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                    ->where('pd.language_id', '=', $langId);
            })
            ->where(function ($sub) use ($q) {
                $sub->where('pd.name', 'like', '%' . $q . '%')
                    ->orWhere('p.sku', 'like', '%' . $q . '%')
                    ->orWhere('p.model', 'like', '%' . $q . '%');
            })
            ->orderByDesc('p.product_id')
            ->limit(5)
            ->get(['p.product_id', 'pd.name', 'p.sku']);

        foreach ($products as $p) {
            $results[] = [
                'category' => 'Products',
                'label' => $p->name,
                'sub' => $p->sku ?: 'ID: ' . $p->product_id,
                'url' => route('products.edit', $p->product_id),
            ];
        }

        // Users
        $users = User::query()
            ->where(function ($sub) use ($q) {
                $sub->where('name', 'like', '%' . $q . '%')
                    ->orWhere('username', 'like', '%' . $q . '%')
                    ->orWhere('email', 'like', '%' . $q . '%');
            })
            ->orderBy('name')
            ->limit(5)
            ->get(['id', 'name', 'email']);

        foreach ($users as $u) {
            $results[] = [
                'category' => 'Users',
                'label' => $u->name,
                'sub' => $u->email,
                'url' => route('users.edit', $u->id),
            ];
        }

        // Activity Log
        $logs = ActivityLog::query()
            ->where('subject_label', 'like', '%' . $q . '%')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'action', 'subject_type', 'subject_label', 'created_at']);

        foreach ($logs as $log) {
            $results[] = [
                'category' => 'Activity',
                'label' => ucfirst($log->action) . ' ' . ($log->subject_type ?? ''),
                'sub' => $log->subject_label,
                'url' => Route::has('ext.audit.activity-log.index') ? route('ext.audit.activity-log.index', ['q' => $q]) : '#',
            ];
        }

        return response()->json(['results' => $results]);
    }
}
