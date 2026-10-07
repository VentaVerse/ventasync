<?php

namespace Extensions\ventacart\Controllers;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use Extensions\ventacart\Models\VentaCartOrder;
use Extensions\ventacart\Models\VentaCartOrderProduct;
use Extensions\ventacart\Models\VentaCartSetting;
use Extensions\ventacart\Services\VentaCart\VentaCartClient;
use Extensions\ventacart\Services\VentaCart\VentaCartFulfilment;
use Extensions\ventacart\Services\VentaCart\VentaCartOrderSync;
use Extensions\ventacart\Services\VentaCartOrdersPanel;
use Illuminate\Http\Request;

class VentaCartOrderController extends Controller
{
    use \App\Http\Controllers\Concerns\DrivesOrderFetchRuns;

    public function serviceability(int $store, int $order)
    {
        [$setting, $ventaCartOrder] = $this->storeAndOrder($store, $order);

        return response()->json(VentaCartFulfilment::for($setting)->serviceability($ventaCartOrder));
    }

    public function pickupSlots(Request $request, int $store, int $order)
    {
        [$setting, $ventaCartOrder] = $this->storeAndOrder($store, $order);
        $courier = trim((string) $request->query('courier', ''));

        return response()->json(VentaCartFulfilment::for($setting)->pickupSlots($ventaCartOrder, $courier !== '' ? $courier : null));
    }

    public function estimate(Request $request, int $store, int $order)
    {
        [$setting, $ventaCartOrder] = $this->storeAndOrder($store, $order);

        return response()->json(VentaCartFulfilment::for($setting)->estimate($ventaCartOrder, $this->bookingOptions($request)));
    }

    public function book(Request $request, int $store, int $order)
    {
        [$setting, $ventaCartOrder] = $this->storeAndOrder($store, $order);

        $result = VentaCartFulfilment::for($setting)->book($ventaCartOrder, $this->bookingOptions($request));

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
            'awb_url' => route('ext.ventacart.orders.awb', [$store, $ventaCartOrder->id]),
        ]);
    }

    public function pickupAddresses(int $store)
    {
        $setting = VentaCartSetting::where('id', $store)->where('enabled', true)->firstOrFail();

        return response()->json(VentaCartFulfilment::for($setting)->pickupAddresses());
    }

    public function shippingCouriers(int $store)
    {
        $setting = VentaCartSetting::where('id', $store)->where('enabled', true)->firstOrFail();

        return response()->json(VentaCartFulfilment::for($setting)->shippingCouriers());
    }

    public function bookManual(Request $request, int $store, int $order)
    {
        [$setting, $ventaCartOrder] = $this->storeAndOrder($store, $order);

        $data = $request->validate([
            'shipping_courier_id' => ['nullable', 'integer'],
            'courier_name'        => ['nullable', 'string', 'max:120'],
            'tracking_number'     => ['nullable', 'string', 'max:190'],
            'comment'             => ['nullable', 'string', 'max:500'],
        ]);

        $result = VentaCartFulfilment::for($setting)->bookManual($ventaCartOrder, $data);

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
        [$setting, $ventaCartOrder] = $this->storeAndOrder($store, $order);

        $result = VentaCartFulfilment::for($setting)->clearManual($ventaCartOrder);

        return back()->with($result['ok'] ? 'status' : 'error', $result['message']);
    }

    public function cancelBooking(int $store, int $order)
    {
        [$setting, $ventaCartOrder] = $this->storeAndOrder($store, $order);

        $result = VentaCartFulfilment::for($setting)->cancel($ventaCartOrder);

        return back()->with($result['ok'] ? 'status' : 'error', $result['message']);
    }

    public function tracking(int $store, int $order)
    {
        [$setting, $ventaCartOrder] = $this->storeAndOrder($store, $order);

        return response()->json(VentaCartFulfilment::for($setting)->tracking($ventaCartOrder));
    }

    public function awb(Request $request, int $store, int $order)
    {
        [$setting, $ventaCartOrder] = $this->storeAndOrder($store, $order);
        $fulfilment = VentaCartFulfilment::for($setting);

        $result = $fulfilment->label($ventaCartOrder, $request->boolean('refresh'));

        if ($request->expectsJson()) {
            return response()->json(['ok' => $result['ok'], 'ready' => $result['ok'], 'message' => $result['message']], $result['ok'] ? 200 : 422);
        }

        if (! $result['ok']) {
            return back()->with('error', $result['message']);
        }

        return response()->file($result['path'], [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="AWB-ventacart-' . (int) $ventaCartOrder->ventacart_order_id . '.pdf"',
        ]);
    }

    private function bookingOptions(Request $request): array
    {
        $data = $request->validate(VentaCartFulfilment::BOOKING_RULES);

        return array_filter($data, fn ($v) => $v !== null && $v !== '');
    }

    private function storeAndOrder(int $store, int $order): array
    {
        $setting = VentaCartSetting::where('id', $store)->where('enabled', true)->firstOrFail();
        $ventaCartOrder = VentaCartOrder::where('ventacart_setting_id', $store)->findOrFail($order);

        return [$setting, $ventaCartOrder];
    }

    public function navRedirect()
    {
        $store = VentaCartSetting::where('enabled', true)->first();
        if (!$store) {
            return redirect()->route('ext.ventacart.index')->with('error', 'No enabled VentaCart store. Add one first.');
        }
        return redirect()->route('ext.ventacart.orders.index', $store->id);
    }

    public function index(Request $request, int $store, VentaCartOrdersPanel $panel)
    {
        VentaCartSetting::findOrFail($store);

        return view('ext-ventacart::orders.index', $panel->build($request, $store));
    }

    public function fetch(Request $request, int $store)
    {
        $setting = VentaCartSetting::where('id', $store)->where('enabled', true)->firstOrFail();
        $dateFrom = $request->filled('date_from') ? (string) $request->input('date_from') : now()->subDays(15)->format('Y-m-d');
        $dateTo = $request->filled('date_to') ? (string) $request->input('date_to') : now()->format('Y-m-d');

        $runner = app(\App\Services\OrderFetchRunner::class);
        $run = $runner->begin('ventacart', (int) $setting->id, $dateFrom, $dateTo, [
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
        return 'ventacart';
    }

    protected function orderFetchStoreId(Request $request): ?int
    {
        return (int) $request->route('store');
    }

    public function orderFetchStep(\App\Models\OrderFetchRun $run): array
    {
        $setting = VentaCartSetting::where('id', (int) $run->store_id)->where('enabled', true)->first();
        if (! $setting) {
            return ['error' => 'This VentaCart store is not enabled, so nothing was fetched. Turn Syncing on under its Connection settings and try again.'];
        }
        $sync = new VentaCartOrderSync(new VentaCartClient($setting), $setting);
        if (! empty($run->options['no_stock'])) {
            $sync->setSkipStockAdjust(true);
        }
        $page = (int) ($run->cursor['page'] ?? 1);
        $log = $sync->pull(since: $run->date_from->format('Y-m-d'), full: (bool) ($run->options['full'] ?? false), maxPages: 1, startPage: $page);
        if ($log->status === 'failed') {
            return ['error' => (string) ($log->error_message ?: 'VentaCart did not answer.')];
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
        $ventaCartOrder = VentaCartOrder::where('ventacart_setting_id', $store)->findOrFail($order);
        $ref = $ventaCartOrder->ventacart_order_id;

        VentaCartOrderProduct::where('ventacart_order_id', $ventaCartOrder->id)->delete();
        $ventaCartOrder->delete();

        ActivityLogger::log('deleted', 'VentaCartOrder', $ref, 'VentaCart #' . $ref);

        return redirect()->route('ext.ventacart.orders.index', $store)->with('status', "VentaCart order #{$ref} deleted.");
    }

    public function bulkDelete(Request $request, int $store)
    {
        $ids = array_map('intval', array_filter((array) $request->input('ids', [])));
        if (empty($ids)) {
            return back()->with('error', 'No orders selected.');
        }

        $orders = VentaCartOrder::where('ventacart_setting_id', $store)->whereIn('id', $ids)->get();
        foreach ($orders as $o) {
            VentaCartOrderProduct::where('ventacart_order_id', $o->id)->delete();
            $o->delete();
        }

        ActivityLogger::log('deleted', 'VentaCartOrder', null, 'Bulk deleted ' . $orders->count() . ' VentaCart orders');

        return back()->with('status', $orders->count() . ' VentaCart order(s) deleted.');
    }
}
