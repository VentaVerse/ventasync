<?php

namespace Extensions\venta\Controllers;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use Extensions\venta\Models\VentaOrder;
use Extensions\venta\Models\VentaOrderProduct;
use Extensions\venta\Models\VentaSetting;
use Extensions\venta\Services\Venta\VentaClient;
use Extensions\venta\Services\Venta\VentaOrderSync;
use Extensions\venta\Services\VentaOrdersPanel;
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

    /**
     * The store's own orders page. Everything the list is made of now lives
     * in VentaOrdersPanel, which the fulfilment hub calls with the same
     * request to render the same partial; this method's remaining job is the
     * 404 for a store that does not exist, which the hub reaches by a
     * different route (a channel key that matches no visible tab).
     */
    public function index(Request $request, int $store, VentaOrdersPanel $panel)
    {
        VentaSetting::findOrFail($store);

        return view('ext-venta::orders.index', $panel->build($request, $store));
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
