<?php

namespace Extensions\ventacart\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Fulfilment\AppFulfilment;
use App\Support\FulfilmentSteps;
use App\Support\Money;
use Extensions\ventacart\Models\VentaCartOrder;
use Extensions\ventacart\Models\VentaCartOrderStatusMap;
use Extensions\ventacart\Models\VentaCartSetting;
use Extensions\ventacart\Services\VentaCart\VentaCartFulfilment;
use Extensions\ventacart\Services\VentaCart\VentaCartStatusPlacements;
use Extensions\ventacart\VentaCartExtension;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class VentaCartOrderApiController extends Controller
{
    public function stores()
    {
        $stores = VentaCartSetting::where('enabled', true)
            ->orderBy('store_name')
            ->get(['id', 'store_name']);

        return response()->json(
            $stores->map(fn ($s) => ['id' => $s->id, 'name' => $s->store_name])
        );
    }

    public function index(Request $request, int $storeId)
    {
        $store = VentaCartSetting::where('id', $storeId)->where('enabled', true)->first();
        if (! $store) {
            return response()->json(['error' => 'Store not found'], 404);
        }

        $tab = trim((string) $request->query('tab', 'ALL'));
        $search = trim((string) $request->query('q', ''));

        $baseQuery = VentaCartOrder::where('ventacart_setting_id', $storeId);
        $query = VentaCartOrder::where('ventacart_setting_id', $storeId);

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
            $buckets = FulfilmentSteps::bucket('ventacart.orders', $statusCounts, VentaCartStatusPlacements::resolver($storeId));
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

            $image = $firstProduct?->raw['image'] ?? null;
            if (empty($image) && $firstProduct) {
                $image = $this->resolveProductImage($firstProduct->sku);
            }
            if ($image && !str_starts_with($image, 'http')) {
                $image = $baseUrl . '/' . ltrim($image, '/');
            }

            return [
                'id'              => $order->id,
                'order_id'        => $order->ventacart_order_number ?: (string) $order->ventacart_order_id,
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
            'step_counts'        => VentaCartExtension::stepCounts($storeId),
        ]);
    }

    public function show(int $storeId, int $id)
    {
        $order = VentaCartOrder::with('products')
            ->where('ventacart_setting_id', $storeId)
            ->find($id);

        if (! $order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        return response()->json($this->payload($order));
    }

    public function payload(VentaCartOrder $order): array
    {
        $order->loadMissing('products');
        $store = VentaCartSetting::find($order->ventacart_setting_id);
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
            'order_id'          => $order->ventacart_order_number ?: (string) $order->ventacart_order_id,
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

    public static function fulfilment(VentaCartOrder $order): array
    {
        $placement = VentaCartStatusPlacements::stepFor((int) $order->ventacart_setting_id, trim((string) $order->status)) ?? 'other';
        $step = match ($placement) {
            'to_pack', 'to_handover', 'cancelled' => $placement,
            'shipping' => 'shipped',
            'delivered' => 'done',
            default => 'other',
        };

        return AppFulfilment::block(
            'ventacart',
            (int) $order->id,
            $step,
            $step === 'to_pack' ? ['book', 'book_manual'] : [],
            in_array($step, ['to_handover', 'shipped'], true) ? ['awb', 'tracking'] : [],
        );
    }

    private function resolveProductImage(?string $sku): ?string
    {
        if (empty($sku)) {
            return null;
        }

        $pfx = (string) config('catalog.prefix');
        $productId = null;

        $pov = DB::table($pfx . 'product_option_value')
            ->where('sku', $sku)
            ->first(['product_id']);

        if ($pov) {
            $productId = $pov->product_id;
        }

        if (! $productId) {
            $product = DB::table($pfx . 'product')
                ->where('sku', $sku)
                ->first(['product_id', 'image']);

            if ($product && $product->image) {
                return url('/storage/' . $product->image);
            }

            return null;
        }

        $product = DB::table($pfx . 'product')
            ->where('product_id', $productId)
            ->first(['image']);

        if ($product && $product->image) {
            return url('/storage/' . $product->image);
        }

        return null;
    }

    public function serviceability(int $store, int $id): JsonResponse
    {
        [$setting, $order] = $this->storeAndOrder($store, $id);

        return response()->json(VentaCartFulfilment::for($setting)->serviceability($order));
    }

    public function pickupSlots(Request $request, int $store, int $id): JsonResponse
    {
        [$setting, $order] = $this->storeAndOrder($store, $id);
        $courier = trim((string) $request->query('courier', ''));

        return response()->json(VentaCartFulfilment::for($setting)->pickupSlots($order, $courier !== '' ? $courier : null));
    }

    public function pickupAddresses(int $store): JsonResponse
    {
        return response()->json(VentaCartFulfilment::for($this->store($store))->pickupAddresses());
    }

    public function shippingCouriers(int $store): JsonResponse
    {
        return response()->json(VentaCartFulfilment::for($this->store($store))->shippingCouriers());
    }

    public function estimate(Request $request, int $store, int $id): JsonResponse
    {
        [$setting, $order] = $this->storeAndOrder($store, $id);

        return response()->json(VentaCartFulfilment::for($setting)->estimate($order, $this->bookingOptions($request)));
    }

    public function book(Request $request, int $store, int $id): JsonResponse
    {
        [$setting, $order] = $this->storeAndOrder($store, $id);
        if ($passed = $this->passed($order)) {
            return $passed;
        }

        $result = VentaCartFulfilment::for($setting)->book($order, $this->bookingOptions($request));
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

        $result = VentaCartFulfilment::for($setting)->bookManual($order, $data);
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

        return response()->json(VentaCartFulfilment::for($setting)->tracking($order));
    }

    public function awb(Request $request, int $store, int $id): Response
    {
        [$setting, $order] = $this->storeAndOrder($store, $id);
        $result = VentaCartFulfilment::for($setting)->label($order, $request->boolean('refresh'));

        $pdf = $result['ok'] && is_file((string) $result['path']) ? file_get_contents($result['path']) : null;

        return AppFulfilment::waybill($pdf ?: null, 'AWB-ventacart-' . (int) $order->ventacart_order_id . '.pdf', true, $result['message']);
    }

    private function passed(VentaCartOrder $order): ?JsonResponse
    {
        $step = self::fulfilment($order)['step'];

        return match ($step) {
            'to_handover', 'shipped', 'done' => AppFulfilment::alreadyDone('This order is already booked.', $this->fresh($order)),
            'cancelled' => AppFulfilment::refused('This order was cancelled.'),
            default => null,
        };
    }

    private function fresh(VentaCartOrder $order): array
    {
        return $this->payload($order->fresh());
    }

    private function bookingOptions(Request $request): array
    {
        $data = $request->validate(VentaCartFulfilment::BOOKING_RULES);

        return array_filter($data, fn ($v) => $v !== null && $v !== '');
    }

    private function store(int $store): VentaCartSetting
    {
        return VentaCartSetting::where('id', $store)->where('enabled', true)->firstOrFail();
    }

    private function storeAndOrder(int $store, int $id): array
    {
        $setting = $this->store($store);

        return [$setting, VentaCartOrder::where('ventacart_setting_id', $store)->findOrFail($id)];
    }
}
