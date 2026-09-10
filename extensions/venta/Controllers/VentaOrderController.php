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

    // ── Courier fulfilment (#168) ───────────────────────────────────────
    //
    // These live on the ORDER controller rather than one of their own so
    // they fall under the order permission pair (view_venta/order for the
    // reads, manage_venta/order for the writes) that every operator already
    // holds or lacks. A FulfilmentController would have minted a third key
    // nobody has been granted. The JSON ones feed the booking modal in
    // resources/js/pages/venta-orders.js; cancel is a plain form with the
    // page's confirm and busy overlay, like Lazada's writes.

    /** Who can carry this parcel: the booking modal's first step. */
    public function serviceability(int $store, int $order)
    {
        [$setting, $ventaOrder] = $this->storeAndOrder($store, $order);

        return response()->json(VentaFulfilment::for($setting)->serviceability($ventaOrder));
    }

    /** Pickup slots for the chosen courier, when it offers them. */
    public function pickupSlots(Request $request, int $store, int $order)
    {
        [$setting, $ventaOrder] = $this->storeAndOrder($store, $order);
        $courier = trim((string) $request->query('courier', ''));

        return response()->json(VentaFulfilment::for($setting)->pickupSlots($ventaOrder, $courier !== '' ? $courier : null));
    }

    /** A fee quote for the booking the modal is about to make. Creates nothing. */
    public function estimate(Request $request, int $store, int $order)
    {
        [$setting, $ventaOrder] = $this->storeAndOrder($store, $order);

        return response()->json(VentaFulfilment::for($setting)->estimate($ventaOrder, $this->bookingOptions($request)));
    }

    /**
     * Book the parcel. Answers JSON for the modal: on success the waybill
     * URL to open and the page reloads into To Handover; on refusal the
     * storefront's own sentence, which is what the packer needs to read.
     */
    public function book(Request $request, int $store, int $order)
    {
        [$setting, $ventaOrder] = $this->storeAndOrder($store, $order);

        $result = VentaFulfilment::for($setting)->book($ventaOrder, $this->bookingOptions($request));

        if (! $result['ok']) {
            return response()->json(['ok' => false, 'message' => $result['message']], 422);
        }

        // The page reloads after the waybill opens; this is what it says
        // on arrival, so "booked" and "in To Handover" are both stated.
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

    /** Where a parcel can leave from: the modal's "Ships from" list. */
    public function pickupAddresses(int $store)
    {
        $setting = VentaSetting::where('id', $store)->where('enabled', true)->firstOrFail();

        return response()->json(VentaFulfilment::for($setting)->pickupAddresses());
    }

    /** The store's couriers a parcel can be handed to by hand: the modal's "another courier" list. */
    public function shippingCouriers(int $store)
    {
        $setting = VentaSetting::where('id', $store)->where('enabled', true)->firstOrFail();

        return response()->json(VentaFulfilment::for($setting)->shippingCouriers());
    }

    /**
     * Record a shipment by hand. Answers JSON for the modal: there is no
     * waybill to open, so success just reloads into To Handover.
     */
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

    /**
     * The waybill. As JSON it answers whether one is ready, which is what
     * the modal polls after booking; as a page it streams the cached PDF,
     * fetching it from the storefront the first time. ?refresh=1 asks the
     * storefront again, for a label reprinted at handover.
     */
    public function awb(Request $request, int $store, int $order)
    {
        [$setting, $ventaOrder] = $this->storeAndOrder($store, $order);
        $fulfilment = VentaFulfilment::for($setting);

        // The store is asked even when the ERP has no booking on record: a
        // parcel booked from the storefront admin has a waybill the ERP
        // knows nothing about until the order payload carries the booking
        // (#169), and the store answers "no booking" itself when there is
        // none. Refusing here would have hidden a printable label.
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

    /**
     * What the modal may send on to the storefront. Only these keys, only
     * when present, so the storefront's per-courier defaults apply to
     * anything the packer left alone.
     */
    private function bookingOptions(Request $request): array
    {
        $data = $request->validate([
            'courier'           => ['nullable', 'string', 'max:32'],
            'service'           => ['nullable', 'string', 'max:64'],
            'parcel'            => ['nullable', 'string', 'max:64'],
            'shipping_payment'  => ['nullable', 'string', 'max:32'],
            'buyer_payment'     => ['nullable', 'in:cod,prepaid'],
            'insure'            => ['nullable', 'boolean'],
            'length_cm'         => ['nullable', 'numeric', 'min:0.01'],
            'width_cm'          => ['nullable', 'numeric', 'min:0.01'],
            'height_cm'         => ['nullable', 'numeric', 'min:0.01'],
            'weight_kg'         => ['nullable', 'numeric', 'min:0.01'],
            'pickup_slot'       => ['nullable', 'string', 'max:255'],
            'pickup_address_id' => ['nullable', 'integer'],
        ]);

        return array_filter($data, fn ($v) => $v !== null && $v !== '');
    }

    /** @return array{0: VentaSetting, 1: VentaOrder} */
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

    /**
     * Fetch orders, as a run of one-page steps (App\Services\OrderFetchRunner,
     * 2026-09-07): the first pages ride this request, the page continues the
     * rest one request per page.
     */
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

    /** One page of the store's orders (a hundred, each with its detail), through the same pull the automation uses. */
    public function orderFetchStep(\App\Models\OrderFetchRun $run): array
    {
        $setting = VentaSetting::where('id', (int) $run->store_id)->where('enabled', true)->first();
        if (! $setting) {
            /* A new store is created with syncing OFF and stays off until
               somebody turns it on, so this is the first thing a freshly
               connected store answers - and saying only "not enabled" left
               the owner looking for a switch he had never been shown
               (2026-09-10). An error says how to fix it or it is only half
               an error. */
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
