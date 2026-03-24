<?php

namespace Extensions\venta\Controllers;

use App\Http\Controllers\Controller;
use Extensions\venta\Models\VentaOrder;
use Extensions\venta\Models\VentaSetting;
use Extensions\venta\Services\Venta\VentaClient;
use Extensions\venta\Services\Venta\VentaOrderSync;
use Illuminate\Http\Request;

class VentaOrderController extends Controller
{
    public function navRedirect()
    {
        $store = VentaSetting::where('enabled', true)->first();
        if (!$store) {
            return redirect()->route('ext.venta.index')->with('error', 'No enabled Venta store. Add one first.');
        }
        return redirect()->route('ext.venta.orders.index', $store->id);
    }

    public function index(Request $request, int $store)
    {
        $setting = VentaSetting::findOrFail($store);

        $query = VentaOrder::where('venta_setting_id', $store)
            ->orderByDesc('order_created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('q')) {
            $q = $request->input('q');
            $query->where(function ($w) use ($q) {
                $w->where('customer_name', 'like', "%{$q}%")
                    ->orWhere('venta_order_id', $q)
                    ->orWhere('venta_order_number', $q)
                    ->orWhere('tracking_number', 'like', "%{$q}%");
            });
        }

        $orders = $query->paginate(50)->withQueryString();

        $statusCounts = VentaOrder::where('venta_setting_id', $store)
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        return view('ext-venta::orders.index', [
            'setting'      => $setting,
            'orders'       => $orders,
            'statusCounts' => $statusCounts,
        ]);
    }

    public function fetch(Request $request, int $store)
    {
        $setting = VentaSetting::where('id', $store)->where('enabled', true)->firstOrFail();
        $client = new VentaClient($setting);
        $sync = new VentaOrderSync($client, $setting);

        if ($request->input('no_stock')) {
            $sync->setSkipStockAdjust(true);
        }

        // Date range support: if date_from is provided, use it as the 'since' parameter
        $since = null;
        if ($request->filled('date_from')) {
            $since = $request->input('date_from');
        }

        $log = $sync->pull(
            since: $since,
            full: (bool) $request->input('full', false)
        );

        if ($log->status === 'failed') {
            return back()->with('error', 'Order sync failed: ' . ($log->error_message ?? 'Unknown error'));
        }

        $parts = [];
        if ($log->records_created > 0) $parts[] = "{$log->records_created} new";
        if ($log->records_updated > 0) $parts[] = "{$log->records_updated} updated";
        if ($log->records_failed > 0)  $parts[] = "{$log->records_failed} failed";
        $msg = empty($parts) ? 'No new orders found.' : 'Orders synced: ' . implode(', ', $parts);

        if ($since) {
            $msg .= " (from {$since})";
        }

        return back()->with('status', $msg);
    }

    public function show(int $store, int $order)
    {
        $setting = VentaSetting::findOrFail($store);
        $ventaOrder = VentaOrder::where('venta_setting_id', $store)
            ->with('products')
            ->findOrFail($order);

        return view('ext-venta::orders.show', [
            'setting' => $setting,
            'order'   => $ventaOrder,
        ]);
    }
}
