<?php

namespace Extensions\venta\Controllers;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use Extensions\venta\Models\VentaOrder;
use Extensions\venta\Models\VentaOrderProduct;
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

        $sortable = ['catalog_order_id', 'venta_order_id', 'venta_order_number', 'customer_name', 'status', 'payment_method', 'total', 'tracking_number', 'order_created_at'];
        $sort = in_array($request->input('sort'), $sortable) ? $request->input('sort') : 'order_created_at';
        $dir = $request->input('dir') === 'asc' ? 'asc' : 'desc';

        // Line items are rendered per order now (one table row each, with the
        // order-level facts rowspanned beside them), so they are loaded with
        // the page rather than lazily per row.
        $query = VentaOrder::where('venta_setting_id', $store)
            ->with('products')
            ->orderBy($sort, $dir);

        // Same guard the page has always used: filled() is false for both a
        // missing param and an empty one, so neither narrows the list.
        $status = $request->filled('status') ? (string) $request->input('status') : '';
        if ($status !== '') {
            $query->where('status', $status);
        }

        $q = $request->filled('q') ? (string) $request->input('q') : '';
        if ($q !== '') {
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
            'orders'       => $orders,
            'statusCounts' => $statusCounts,
            'sort'         => $sort,
            'dir'          => $dir,
            // The store this page belongs to, named rather than passed as a
            // whole settings row: the page needs its id (for its own links)
            // and its name (for the title), and nothing else on that model.
            'storeId'      => $store,
            'storeLabel'   => (string) $setting->store_name,
            'filters'      => ['q' => $q, 'status' => $status],
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

    public function destroy(int $store, int $order)
    {
        $ventaOrder = VentaOrder::where('venta_setting_id', $store)->findOrFail($order);
        $ref = $ventaOrder->venta_order_id;

        VentaOrderProduct::where('venta_order_id', $ventaOrder->id)->delete();
        $ventaOrder->delete();

        ActivityLogger::log('deleted', 'VentaOrder', $ref, 'Venta #' . $ref);

        return redirect()->route('ext.venta.orders.index', $store)->with('status', "Venta order #{$ref} deleted.");
    }

    public function bulkDelete(Request $request, int $store)
    {
        $ids = array_map('intval', array_filter((array) $request->input('ids', [])));
        if (empty($ids)) {
            return back()->with('error', 'No orders selected.');
        }

        $orders = VentaOrder::where('venta_setting_id', $store)->whereIn('id', $ids)->get();
        foreach ($orders as $o) {
            VentaOrderProduct::where('venta_order_id', $o->id)->delete();
            $o->delete();
        }

        ActivityLogger::log('deleted', 'VentaOrder', null, 'Bulk deleted ' . $orders->count() . ' Venta orders');

        return back()->with('status', $orders->count() . ' Venta order(s) deleted.');
    }
}
