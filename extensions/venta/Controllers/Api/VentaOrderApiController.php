<?php

namespace Extensions\venta\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Fulfilment\AppFulfilment;
use App\Support\FulfilmentSteps;
use App\Support\Money;
use Extensions\venta\Models\VentaOrder;
use Extensions\venta\Models\VentaOrderStatusMap;
use Extensions\venta\Models\VentaSetting;
use Extensions\venta\Services\Venta\VentaFulfilment;
use Extensions\venta\Services\Venta\VentaStatusPlacements;
use Extensions\venta\VentaExtension;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class VentaOrderApiController extends Controller
{
    /**
     * List enabled Venta stores.
     */
    public function stores()
    {
        $stores = VentaSetting::where('enabled', true)
            ->orderBy('store_name')
            ->get(['id', 'store_name']);

        return response()->json(
            $stores->map(fn ($s) => ['id' => $s->id, 'name' => $s->store_name])
        );
    }

    /**
     * List orders for a Venta store with tab filtering and search.
     */
    public function index(Request $request, int $storeId)
    {
        $store = VentaSetting::where('id', $storeId)->where('enabled', true)->first();
        if (! $store) {
            return response()->json(['error' => 'Store not found'], 404);
        }

        $tab = trim((string) $request->query('tab', 'ALL'));
        $search = trim((string) $request->query('q', ''));

        // Base query scoped to this store
        $baseQuery = VentaOrder::where('venta_setting_id', $storeId);
        $query = VentaOrder::where('venta_setting_id', $storeId);

        // Get distinct statuses for dynamic tabs
        $distinctStatuses = (clone $baseQuery)->select('status')
            ->distinct()
            ->pluck('status')
            ->filter()
            ->sort()
            ->values()
            ->toArray();

        // Tab filter
        if ($tab !== 'ALL' && $tab !== '') {
            $query->where('status', $tab);
        }

        // Step filter: the web's To Pack and To Handover, by the statuses
        // the store places in each.
        $step = trim((string) $request->query('step', ''));
        if (in_array($step, ['to_pack', 'to_handover'], true)) {
            $statusCounts = (clone $baseQuery)->selectRaw('status, COUNT(*) as cnt')->groupBy('status')->pluck('cnt', 'status');
            $buckets = FulfilmentSteps::bucket('venta.orders', $statusCounts, VentaStatusPlacements::resolver($storeId));
            $query->whereIn('status', $buckets[$step]['statuses'] ?: ['']);
        }

        // Search filter
        if ($search !== '') {
            $like = '%' . $search . '%';
            $query->where(function ($q) use ($like) {
                $q->where('customer_name', 'like', $like)
                    ->orWhere('venta_order_id', 'like', $like)
                    ->orWhere('venta_order_number', 'like', $like)
                    ->orWhere('tracking_number', 'like', $like);
            });
        }

        // Tab counts
        $tabCounts = ['ALL' => (clone $baseQuery)->count()];
        foreach ($distinctStatuses as $status) {
            $tabCounts[$status] = (clone $baseQuery)->where('status', $status)->count();
        }

        // Paginate
        $orders = $query
            ->orderByDesc('order_created_at')
            ->orderByDesc('created_at')
            ->paginate(20);

        // Transform
        $baseUrl = rtrim($store->base_url ?? '', '/');
        $data = $orders->map(function ($order) use ($baseUrl) {
            $firstProduct = $order->products()->orderBy('id')->first();
            $raw = $order->raw ?? [];

            $image = $firstProduct?->raw['image'] ?? null;
            if (empty($image) && $firstProduct) {
                $image = $this->resolveProductImage($firstProduct->sku);
            }
            if ($image && !str_starts_with($image, 'http')) {
                $image = $baseUrl . '/' . ltrim($image, '/');
            }

            return [
                'id'              => $order->id,
                'order_id'        => $order->venta_order_number ?: (string) $order->venta_order_id,
                'status'          => $order->status ?? '',
                'buyer_name'      => $order->customer_name ?? '',
                'total_amount'    => $order->total !== null ? (float) $order->total : null,
                'currency'        => $raw['currency'] ?? Money::defaultCode(),
                'items_count'     => $order->products()->count(),
                'product_image'   => $image,
                'payment_method'  => $order->payment_method,
                'tracking_number' => $order->tracking_number,
                'date'            => $order->order_created_at
                    ? $order->order_created_at->format('Y-m-d H:i')
                    : null,
                'fulfilment'      => self::fulfilment($order),
            ];
        });

        return response()->json([
            'data'               => $data,
            'current_page'       => $orders->currentPage(),
            'last_page'          => $orders->lastPage(),
            'total'              => $orders->total(),
            'tab_counts'         => $tabCounts,
            'pending_sub_counts' => (object) [],
            'step_counts'        => VentaExtension::stepCounts($storeId),
        ]);
    }

    /**
     * Show a single Venta order detail.
     */
    public function show(int $storeId, int $id)
    {
        $order = VentaOrder::with('products')
            ->where('venta_setting_id', $storeId)
            ->find($id);

        if (! $order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        return response()->json($this->payload($order));
    }

    /**
     * An order as the show endpoint draws it, which is also what every
     * fulfilment action hands back after it acts.
     *
     * @return array<string, mixed>
     */
    public function payload(VentaOrder $order): array
    {
        $order->loadMissing('products');
        $store = VentaSetting::find($order->venta_setting_id);
        $baseUrl = rtrim($store->base_url ?? '', '/');
        $raw = $order->raw ?? [];
        $shippingAddr = $order->shipping_address ?? [];

        $products = $order->products->map(function ($p) use ($baseUrl) {
            $image = $p->raw['image'] ?? '';
            if (empty($image)) {
                $image = $this->resolveProductImage($p->sku) ?? '';
            }
            if ($image && !str_starts_with($image, 'http')) {
                $image = $baseUrl . '/' . ltrim($image, '/');
            }
            return [
                'name'      => $p->name ?? '',
                'sku'       => $p->sku ?? '',
                'variation' => $p->variant_label ?? '',
                'quantity'  => (int) $p->quantity,
                'price'     => (float) $p->price,
                'image'     => $image,
            ];
        });

        return [
            'id'                => $order->id,
            'order_id'          => $order->venta_order_number ?: (string) $order->venta_order_id,
            'status'            => $order->status ?? '',
            'buyer_name'        => $order->customer_name ?? '',
            'total_amount'      => $order->total !== null ? (float) $order->total : null,
            'currency'          => $raw['currency'] ?? Money::defaultCode(),
            'payment_method'    => $order->payment_method ?? '',
            'shipping_provider' => $order->shipping_method ?? '',
            'tracking_number'   => $order->tracking_number ?? '',
            'shipping_name'     => $shippingAddr['name'] ?? $shippingAddr['first_name'] ?? '',
            'shipping_phone'    => $shippingAddr['phone'] ?? $shippingAddr['telephone'] ?? '',
            'shipping_address'  => trim(implode(', ', array_filter([
                $shippingAddr['address_1'] ?? $shippingAddr['address'] ?? '',
                $shippingAddr['address_2'] ?? '',
                $shippingAddr['city'] ?? '',
                $shippingAddr['zone'] ?? $shippingAddr['state'] ?? '',
                $shippingAddr['postcode'] ?? $shippingAddr['zip'] ?? '',
                $shippingAddr['country'] ?? '',
            ]))),
            'date'              => $order->order_created_at
                ? $order->order_created_at->format('Y-m-d H:i')
                : null,
            'products'          => $products->values(),
            'fulfilment'        => self::fulfilment($order),
        ];
    }

    /**
     * The order's step as the web's VentaCart panel places it, and what it
     * allows: a parcel on the bench is booked with a courier or by hand,
     * one past it has a waybill and tracking.
     */
    public static function fulfilment(VentaOrder $order): array
    {
        $placement = VentaStatusPlacements::stepFor((int) $order->venta_setting_id, trim((string) $order->status)) ?? 'other';
        $step = match ($placement) {
            'to_pack', 'to_handover', 'cancelled' => $placement,
            'shipping' => 'shipped',
            'delivered' => 'done',
            default => 'other',
        };

        return AppFulfilment::block(
            'venta',
            (int) $order->id,
            $step,
            $step === 'to_pack' ? ['book', 'book_manual'] : [],
            in_array($step, ['to_handover', 'shipped'], true) ? ['awb', 'tracking'] : [],
        );
    }

    /**
     * Resolve a product image from the ERP catalog by SKU.
     *
     * Checks product_option_value first (variant SKU), then product table.
     * Returns a full URL to the ERP image, or null if not found.
     */
    private function resolveProductImage(?string $sku): ?string
    {
        if (empty($sku)) {
            return null;
        }

        $pfx = (string) config('catalog.prefix');
        $productId = null;

        // 1. Try variant SKU in product_option_value table
        $pov = DB::table($pfx . 'product_option_value')
            ->where('sku', $sku)
            ->first(['product_id']);

        if ($pov) {
            $productId = $pov->product_id;
        }

        // 2. Fallback: try product table directly
        if (! $productId) {
            $product = DB::table($pfx . 'product')
                ->where('sku', $sku)
                ->first(['product_id', 'image']);

            if ($product && $product->image) {
                return url('/storage/' . $product->image);
            }

            return null;
        }

        // 3. Get image from product table using the resolved product_id
        $product = DB::table($pfx . 'product')
            ->where('product_id', $productId)
            ->first(['image']);

        if ($product && $product->image) {
            return url('/storage/' . $product->image);
        }

        return null;
    }

    // The web's booking buttons, for the app: the same storefront calls the
    // booking modal makes, through the same service, in the app's shape.

    public function serviceability(int $store, int $id): JsonResponse
    {
        [$setting, $order] = $this->storeAndOrder($store, $id);

        return response()->json(VentaFulfilment::for($setting)->serviceability($order));
    }

    public function pickupSlots(Request $request, int $store, int $id): JsonResponse
    {
        [$setting, $order] = $this->storeAndOrder($store, $id);
        $courier = trim((string) $request->query('courier', ''));

        return response()->json(VentaFulfilment::for($setting)->pickupSlots($order, $courier !== '' ? $courier : null));
    }

    public function pickupAddresses(int $store): JsonResponse
    {
        return response()->json(VentaFulfilment::for($this->store($store))->pickupAddresses());
    }

    public function shippingCouriers(int $store): JsonResponse
    {
        return response()->json(VentaFulfilment::for($this->store($store))->shippingCouriers());
    }

    public function estimate(Request $request, int $store, int $id): JsonResponse
    {
        [$setting, $order] = $this->storeAndOrder($store, $id);

        return response()->json(VentaFulfilment::for($setting)->estimate($order, $this->bookingOptions($request)));
    }

    public function book(Request $request, int $store, int $id): JsonResponse
    {
        [$setting, $order] = $this->storeAndOrder($store, $id);
        if ($passed = $this->passed($order)) {
            return $passed;
        }

        $result = VentaFulfilment::for($setting)->book($order, $this->bookingOptions($request));
        if (! $result['ok']) {
            return AppFulfilment::refused($result['message']);
        }
        if ($result['already_booked']) {
            return AppFulfilment::alreadyDone($result['message'], $this->fresh($order));
        }

        return AppFulfilment::ok($result['message'], $this->fresh($order), [
            'courier' => $result['courier'],
            'tracking_number' => $result['tracking_number'],
        ]);
    }

    public function bookManual(Request $request, int $store, int $id): JsonResponse
    {
        [$setting, $order] = $this->storeAndOrder($store, $id);
        $data = $request->validate([
            'shipping_courier_id' => ['nullable', 'integer'],
            'courier_name' => ['nullable', 'string', 'max:120'],
            'tracking_number' => ['nullable', 'string', 'max:190'],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);
        if ($passed = $this->passed($order)) {
            return $passed;
        }

        $result = VentaFulfilment::for($setting)->bookManual($order, $data);
        if (! $result['ok']) {
            return AppFulfilment::refused($result['message']);
        }

        return AppFulfilment::ok($result['message'], $this->fresh($order), [
            'courier_name' => $result['courier_name'],
            'tracking_number' => $result['tracking_number'],
        ]);
    }

    public function tracking(int $store, int $id): JsonResponse
    {
        [$setting, $order] = $this->storeAndOrder($store, $id);

        return response()->json(VentaFulfilment::for($setting)->tracking($order));
    }

    public function awb(Request $request, int $store, int $id): Response
    {
        [$setting, $order] = $this->storeAndOrder($store, $id);
        $result = VentaFulfilment::for($setting)->label($order, $request->boolean('refresh'));

        $pdf = $result['ok'] && is_file((string) $result['path']) ? file_get_contents($result['path']) : null;

        return AppFulfilment::waybill($pdf ?: null, 'AWB-venta-' . (int) $order->venta_order_id . '.pdf', false, $result['message']);
    }

    /** An order already past To Pack is answered here; the storefront is not asked again. */
    private function passed(VentaOrder $order): ?JsonResponse
    {
        $step = self::fulfilment($order)['step'];

        return match ($step) {
            'to_handover', 'shipped', 'done' => AppFulfilment::alreadyDone('This order is already booked.', $this->fresh($order)),
            'cancelled' => AppFulfilment::refused('This order was cancelled.'),
            default => null,
        };
    }

    private function fresh(VentaOrder $order): array
    {
        return $this->payload($order->fresh());
    }

    /** The same booking options the web's modal may send on to the storefront. */
    private function bookingOptions(Request $request): array
    {
        $data = $request->validate(VentaFulfilment::BOOKING_RULES);

        return array_filter($data, fn ($v) => $v !== null && $v !== '');
    }

    private function store(int $store): VentaSetting
    {
        return VentaSetting::where('id', $store)->where('enabled', true)->firstOrFail();
    }

    /** @return array{0: VentaSetting, 1: VentaOrder} */
    private function storeAndOrder(int $store, int $id): array
    {
        $setting = $this->store($store);

        return [$setting, VentaOrder::where('venta_setting_id', $store)->findOrFail($id)];
    }
}
