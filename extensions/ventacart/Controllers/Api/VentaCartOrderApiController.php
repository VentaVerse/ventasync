<?php

namespace Extensions\VentaCart\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Fulfillment\AppFulfillment;
use App\Support\FulfillmentSteps;
use App\Support\Money;
use Extensions\VentaCart\Models\VentaCartOrder;
use Extensions\VentaCart\Models\VentaCartOrderStatusMap;
use Extensions\VentaCart\Models\VentaCartStore;
use Extensions\VentaCart\Services\VentaCart\VentaCartFulfillment;
use Extensions\VentaCart\Services\VentaCart\VentaCartStatusPlacements;
use Extensions\VentaCart\VentaCartExtension;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class VentaCartOrderApiController extends Controller
{
    public function stores()
    {
        $stores = VentaCartStore::where('enabled', true)
            ->orderBy('store_name')
            ->get(['id', 'store_name']);

        return response()->json(
            $stores->map(fn ($s) => ['id' => $s->id, 'name' => $s->store_name])
        );
    }

    public function index(Request $request, int $storeId)
    {
        $store = VentaCartStore::where('id', $storeId)->where('enabled', true)->first();
        if (! $store) {
            return response()->json(['error' => 'Store not found'], 404);
        }

        $tab = trim((string) $request->query('tab', 'ALL'));
        $search = trim((string) $request->query('q', ''));

        $baseQuery = VentaCartOrder::where('store_id', $storeId);
        $query = VentaCartOrder::where('store_id', $storeId);

        $distinctStatuses = (clone $baseQuery)->select('status')
            ->distinct()
            ->pluck('status')
            ->filter()
            ->sort()
            ->values()
            ->toArray();

        if ($tab !== 'ALL' && $tab !== '') {
            $query->where('status', $tab);
        }

        $step = trim((string) $request->query('step', ''));
        if (in_array($step, ['to_pack', 'to_handover'], true)) {
            $statusCounts = (clone $baseQuery)->selectRaw('status, COUNT(*) as cnt')->groupBy('status')->pluck('cnt', 'status');
            $buckets = FulfillmentSteps::bucket('ventacart.orders', $statusCounts, VentaCartStatusPlacements::resolver($storeId));
            $query->whereIn('status', $buckets[$step]['statuses'] ?: ['']);
        }

        if ($search !== '') {
            $like = '%' . $search . '%';
            $query->where(function ($q) use ($like) {
                $q->where('customer_name', 'like', $like)
                    ->orWhere('ventacart_order_id', 'like', $like)
                    ->orWhere('ventacart_order_number', 'like', $like)
                    ->orWhere('tracking_number', 'like', $like);
            });
        }

        $tabCounts = ['ALL' => (clone $baseQuery)->count()];
        foreach ($distinctStatuses as $status) {
            $tabCounts[$status] = (clone $baseQuery)->where('status', $status)->count();
        }

        $orders = $query
            ->orderByDesc('order_created_at')
            ->orderByDesc('created_at')
            ->paginate(20);

        $baseUrl = rtrim($store->base_url ?? '', '/');
        $data = $orders->map(function ($order) use ($baseUrl) {
            $firstProduct = $order->products()->orderBy('id')->first();
            $raw = $order->raw ?? [];

            $storeImage = (string) ($firstProduct?->raw['image'] ?? '');
            if ($storeImage !== '' && !str_starts_with($storeImage, 'http')) {
                $storeImage = $baseUrl . '/' . ltrim($storeImage, '/');
            }
            $image = $firstProduct ? \App\Support\LineImage::url($firstProduct->sku, $storeImage) : null;

            return [
                'id'              => $order->id,
                'order_id'        => $order->ventacart_order_number ?: (string) $order->ventacart_order_id,
                'status'          => $order->status ?? '',
                'customer_name'   => $order->customer_name ?? '',
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
                'fulfillment'      => self::fulfillment($order),
                'fulfilment'       => self::fulfillment($order),
            ];
        });

        return response()->json([
            'data'               => $data,
            'current_page'       => $orders->currentPage(),
            'last_page'          => $orders->lastPage(),
            'total'              => $orders->total(),
            'tab_counts'         => $tabCounts,
            'pending_sub_counts' => (object) [],
            'step_counts'        => VentaCartExtension::stepCounts($storeId),
        ]);
    }

    public function show(int $storeId, int $id)
    {
        $order = VentaCartOrder::with('products')
            ->where('store_id', $storeId)
            ->find($id);

        if (! $order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        return response()->json($this->payload($order));
    }

    public function payload(VentaCartOrder $order): array
    {
        $order->loadMissing('products');
        $store = VentaCartStore::find($order->store_id);
        $baseUrl = rtrim($store->base_url ?? '', '/');
        $raw = $order->raw ?? [];
        $shippingAddr = $order->shipping_address ?? [];

        $products = $order->products->map(function ($p) use ($baseUrl, $order) {
            $storeImage = (string) ($p->raw['image'] ?? '');
            if ($storeImage !== '' && !str_starts_with($storeImage, 'http')) {
                $storeImage = $baseUrl . '/' . ltrim($storeImage, '/');
            }
            $image = \App\Support\LineImage::url($p->sku, $storeImage) ?? '';
            return [
                'name'      => $p->name ?? '',
                'sku'       => $p->sku ?? '',
                'variation' => \App\Support\LineVariation::label($p->sku, $p->variation_label, $order->sales_order_id, $p->name),
                'quantity'  => (int) $p->quantity,
                'price'     => (float) $p->price,
                'image'     => $image,
            ];
        });

        return [
            'id'                => $order->id,
            'order_id'          => $order->ventacart_order_number ?: (string) $order->ventacart_order_id,
            'status'            => $order->status ?? '',
            'customer_name'     => $order->customer_name ?? '',
            'buyer_name'        => $order->customer_name ?? '',
            'total_amount'      => $order->total !== null ? (float) $order->total : null,
            'currency'          => $raw['currency'] ?? Money::defaultCode(),
            'payment_method'    => $order->payment_method ?? '',
            'shipping_provider' => $order->shipping_method ?? '',
            'tracking_number'   => $order->tracking_number ?? '',
            'shipping_name'     => $shippingAddr['name'] ?? $shippingAddr['first_name'] ?? '',
            'shipping_telephone' => $shippingAddr['phone'] ?? $shippingAddr['telephone'] ?? '',
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
            'fulfillment'        => self::fulfillment($order),
            'fulfilment'         => self::fulfillment($order),
        ];
    }

    public static function fulfillment(VentaCartOrder $order): array
    {
        $placement = VentaCartStatusPlacements::stepFor((int) $order->store_id, trim((string) $order->status)) ?? 'other';
        $step = match ($placement) {
            'to_pack', 'to_handover', 'canceled' => $placement,
            'shipping' => 'shipped',
            'delivered' => 'done',
            default => 'other',
        };

        return AppFulfillment::block(
            'ventacart',
            (int) $order->id,
            $step,
            $step === 'to_pack' ? ['book', 'book_manual'] : [],
            in_array($step, ['to_handover', 'shipped'], true) ? ['awb', 'tracking'] : [],
        );
    }

    public function serviceability(int $store, int $id): JsonResponse
    {
        [$setting, $order] = $this->storeAndOrder($store, $id);

        return response()->json(VentaCartFulfillment::for($setting)->serviceability($order));
    }

    public function pickupSlots(Request $request, int $store, int $id): JsonResponse
    {
        [$setting, $order] = $this->storeAndOrder($store, $id);
        $courier = trim((string) $request->query('courier', ''));

        return response()->json(VentaCartFulfillment::for($setting)->pickupSlots($order, $courier !== '' ? $courier : null));
    }

    public function pickupAddresses(int $store): JsonResponse
    {
        return response()->json(VentaCartFulfillment::for($this->store($store))->pickupAddresses());
    }

    public function shippingCouriers(int $store): JsonResponse
    {
        return response()->json(VentaCartFulfillment::for($this->store($store))->shippingCouriers());
    }

    public function estimate(Request $request, int $store, int $id): JsonResponse
    {
        [$setting, $order] = $this->storeAndOrder($store, $id);

        return response()->json(VentaCartFulfillment::for($setting)->estimate($order, $this->bookingOptions($request)));
    }

    public function book(Request $request, int $store, int $id): JsonResponse
    {
        [$setting, $order] = $this->storeAndOrder($store, $id);
        if ($passed = $this->passed($order)) {
            return $passed;
        }

        $result = VentaCartFulfillment::for($setting)->book($order, $this->bookingOptions($request));
        if (! $result['ok']) {
            return AppFulfillment::refused($result['message']);
        }
        if ($result['already_booked']) {
            return AppFulfillment::alreadyDone($result['message'], $this->fresh($order));
        }

        return AppFulfillment::ok($result['message'], $this->fresh($order), [
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

        $result = VentaCartFulfillment::for($setting)->bookManual($order, $data);
        if (! $result['ok']) {
            return AppFulfillment::refused($result['message']);
        }

        return AppFulfillment::ok($result['message'], $this->fresh($order), [
            'courier_name' => $result['courier_name'],
            'tracking_number' => $result['tracking_number'],
        ]);
    }

    public function tracking(int $store, int $id): JsonResponse
    {
        [$setting, $order] = $this->storeAndOrder($store, $id);

        return response()->json(VentaCartFulfillment::for($setting)->tracking($order));
    }

    public function awb(Request $request, int $store, int $id): Response
    {
        [$setting, $order] = $this->storeAndOrder($store, $id);
        $result = VentaCartFulfillment::for($setting)->label($order, $request->boolean('refresh'));

        $pdf = $result['ok'] && is_file((string) $result['path']) ? file_get_contents($result['path']) : null;

        return AppFulfillment::waybill($pdf ?: null, 'AWB-ventacart-' . (int) $order->ventacart_order_id . '.pdf', true, $result['message']);
    }

    private function passed(VentaCartOrder $order): ?JsonResponse
    {
        $step = self::fulfillment($order)['step'];

        return match ($step) {
            'to_handover', 'shipped', 'done' => AppFulfillment::alreadyDone('This order is already booked.', $this->fresh($order)),
            'canceled' => AppFulfillment::refused('This order was canceled.'),
            default => null,
        };
    }

    private function fresh(VentaCartOrder $order): array
    {
        return $this->payload($order->fresh());
    }

    private function bookingOptions(Request $request): array
    {
        $data = $request->validate(VentaCartFulfillment::BOOKING_RULES);

        return array_filter($data, fn ($v) => $v !== null && $v !== '');
    }

    private function store(int $store): VentaCartStore
    {
        return VentaCartStore::where('id', $store)->where('enabled', true)->firstOrFail();
    }

    private function storeAndOrder(int $store, int $id): array
    {
        $setting = $this->store($store);

        return [$setting, VentaCartOrder::where('store_id', $store)->findOrFail($id)];
    }
}
