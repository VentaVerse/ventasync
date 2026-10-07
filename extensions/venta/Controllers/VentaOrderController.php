<?php

namespace Extensions\venta\Controllers;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use Extensions\venta\Models\VentaOrder;
use Extensions\venta\Models\VentaOrderProduct;
use Extensions\venta\Models\VentaSetting;
use Extensions\venta\Services\Venta\VentaClient;
use Extensions\venta\Services\Venta\VentaFulfilment;
use Extensions\venta\Services\Venta\VentaOrderSync;
use Extensions\venta\Services\VentaOrdersPanel;
use Illuminate\Http\Request;

class VentaOrderController extends Controller
{
    use \App\Http\Controllers\Concerns\DrivesOrderFetchRuns;

    public function serviceability(int $store, int $order)
    {
        [$setting, $ventaOrder] = $this->storeAndOrder($store, $order);

        return response()->json(VentaFulfilment::for($setting)->serviceability($ventaOrder));
    }

    public function pickupSlots(Request $request, int $store, int $order)
    {
        [$setting, $ventaOrder] = $this->storeAndOrder($store, $order);
        $courier = trim((string) $request->query('courier', ''));

        return response()->json(VentaFulfilment::for($setting)->pickupSlots($ventaOrder, $courier !== '' ? $courier : null));
    }

    public function estimate(Request $request, int $store, int $order)
    {
        [$setting, $ventaOrder] = $this->storeAndOrder($store, $order);

        return response()->json(VentaFulfilment::for($setting)->estimate($ventaOrder, $this->bookingOptions($request)));
    }

    public function book(Request $request, int $store, int $order)
    {
        [$setting, $ventaOrder] = $this->storeAndOrder($store, $order);

        $result = VentaFulfilment::for($setting)->book($ventaOrder, $this->bookingOptions($request));

        if (! $result['ok']) {
            return response()->json(['ok' => false, 'message' => $result['message']], 422);
        }

        session()->flash(($result['landing']['step'] ?? '') === 'to_handover' ? 'status' : 'warning', $result['message']);

        return response()->json([
            'ok' => true,
            'message' => $result['message'],
            'already_booked' => $result['already_booked'],
            'courier' => $result['courier'],
            'tracking_number' => $result['tracking_number'],
            'awb_url' => route('ext.venta.orders.awb', [$store, $ventaOrder->id]),
        ]);
    }

    public function pickupAddresses(int $store)
    {
        $setting = VentaSetting::where('id', $store)->where('enabled', true)->firstOrFail();

        return response()->json(VentaFulfilment::for($setting)->pickupAddresses());
    }

    public function shippingCouriers(int $store)
    {
        $setting = VentaSetting::where('id', $store)->where('enabled', true)->firstOrFail();

        return response()->json(VentaFulfilment::for($setting)->shippingCouriers());
    }

    public function bookManual(Request $request, int $store, int $order)
    {
        [$setting, $ventaOrder] = $this->storeAndOrder($store, $order);

        $data = $request->validate([
            'shipping_courier_id' => ['nullable', 'integer'],
            'courier_name'        => ['nullable', 'string', 'max:120'],
            'tracking_number'     => ['nullable', 'string', 'max:190'],
            'comment'             => ['nullable', 'string', 'max:500'],
        ]);

        $result = VentaFulfilment::for($setting)->bookManual($ventaOrder, $data);

        if (! $result['ok']) {
            return response()->json(['ok' => false, 'message' => $result['message']], 422);
        }

        session()->flash(($result['landing']['step'] ?? '') === 'to_handover' ? 'status' : 'warning', $result['message']);

        return response()->json([
            'ok' => true,
            'message' => $result['message'],
            'courier_name' => $result['courier_name'],
            'tracking_number' => $result['tracking_number'],
        ]);
    }

    public function clearManual(int $store, int $order)
    {
        [$setting, $ventaOrder] = $this->storeAndOrder($store, $order);

        $result = VentaFulfilment::for($setting)->clearManual($ventaOrder);

        return back()->with($result['ok'] ? 'status' : 'error', $result['message']);
    }

    public function cancelBooking(int $store, int $order)
    {
        [$setting, $ventaOrder] = $this->storeAndOrder($store, $order);

        $result = VentaFulfilment::for($setting)->cancel($ventaOrder);

        return back()->with($result['ok'] ? 'status' : 'error', $result['message']);
    }

    public function tracking(int $store, int $order)
    {
        [$setting, $ventaOrder] = $this->storeAndOrder($store, $order);

        return response()->json(VentaFulfilment::for($setting)->tracking($ventaOrder));
    }

    public function awb(Request $request, int $store, int $order)
    {
        [$setting, $ventaOrder] = $this->storeAndOrder($store, $order);
        $fulfilment = VentaFulfilment::for($setting);

        $result = $fulfilment->label($ventaOrder, $request->boolean('refresh'));

        if ($request->expectsJson()) {
            return response()->json(['ok' => $result['ok'], 'ready' => $result['ok'], 'message' => $result['message']], $result['ok'] ? 200 : 422);
        }

        if (! $result['ok']) {
            return back()->with('error', $result['message']);
        }

        return response()->file($result['path'], [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="AWB-venta-' . (int) $ventaOrder->venta_order_id . '.pdf"',
        ]);
    }

    private function bookingOptions(Request $request): array
    {
        $data = $request->validate(VentaFulfilment::BOOKING_RULES);

        return array_filter($data, fn ($v) => $v !== null && $v !== '');
    }

    private function storeAndOrder(int $store, int $order): array
    {
        $setting = VentaSetting::where('id', $store)->where('enabled', true)->firstOrFail();
        $ventaOrder = VentaOrder::where('venta_setting_id', $store)->findOrFail($order);

        return [$setting, $ventaOrder];
    }

    public function navRedirect()
    {
        $store = VentaSetting::where('enabled', true)->first();
        if (!$store) {
            return redirect()->route('ext.venta.index')->with('error', 'No enabled Venta store. Add one first.');
        }
        return redirect()->route('ext.venta.orders.index', $store->id);
    }

    public function index(Request $request, int $store, VentaOrdersPanel $panel)
    {
        VentaSetting::findOrFail($store);

        return view('ext-venta::orders.index', $panel->build($request, $store));
    }

    public function fetch(Request $request, int $store)
    {
        $setting = VentaSetting::where('id', $store)->where('enabled', true)->firstOrFail();
        $dateFrom = $request->filled('date_from') ? (string) $request->input('date_from') : now()->subDays(15)->format('Y-m-d');
        $dateTo = $request->filled('date_to') ? (string) $request->input('date_to') : now()->format('Y-m-d');

        $runner = app(\App\Services\OrderFetchRunner::class);
        $run = $runner->begin('venta', (int) $setting->id, $dateFrom, $dateTo, [
            'no_stock' => (bool) $request->input('no_stock'), 'full' => (bool) $request->input('full', false),
        ]);
        $run = $runner->walk($run, fn (\App\Models\OrderFetchRun $r) => $this->orderFetchStep($r));

        if ($run->status === \App\Models\OrderFetchRun::FAILED) {
            return back()->with('error', 'Order sync failed: ' . ($run->last_error ?? 'Unknown error'));
        }

        return back()->with('status', \App\Services\OrderFetchRunner::outcome($run) . ($run->status === \App\Models\OrderFetchRun::RUNNING ? ' The page continues the rest.' : ''));
    }

    protected function orderFetchIntegration(): string
    {
        return 'venta';
    }

    protected function orderFetchStoreId(Request $request): ?int
    {
        return (int) $request->route('store');
    }

    public function orderFetchStep(\App\Models\OrderFetchRun $run): array
    {
        $setting = VentaSetting::where('id', (int) $run->store_id)->where('enabled', true)->first();
        if (! $setting) {
            return ['error' => 'This Venta store is not enabled, so nothing was fetched. Turn Syncing on under its Connection settings and try again.'];
        }
        $sync = new VentaOrderSync(new VentaClient($setting), $setting);
        if (! empty($run->options['no_stock'])) {
            $sync->setSkipStockAdjust(true);
        }
        $page = (int) ($run->cursor['page'] ?? 1);
        $log = $sync->pull(since: $run->date_from->format('Y-m-d'), full: (bool) ($run->options['full'] ?? false), maxPages: 1, startPage: $page);
        if ($log->status === 'failed') {
            return ['error' => (string) ($log->error_message ?: 'Venta did not answer.')];
        }
        $pages = (int) ($sync->lastTotalPages ?? 1);
        $done = $page >= $pages;

        return [
            'read' => (int) $log->records_processed, 'created' => (int) $log->records_created, 'updated' => (int) $log->records_updated, 'failed' => (int) $log->records_failed,
            'done' => $done, 'cursor' => $done ? null : ['page' => $page + 1], 'pages' => $pages,
        ];
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
