<?php

namespace Extensions\venta\Controllers\Api;

use App\Http\Controllers\Controller;
use Extensions\venta\Models\VentaOrder;
use Extensions\venta\Models\VentaOrderStatusMap;
use Extensions\venta\Models\VentaSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
                'currency'        => $raw['currency'] ?? 'PHP',
                'items_count'     => $order->products()->count(),
                'product_image'   => $image,
                'payment_method'  => $order->payment_method,
                'tracking_number' => $order->tracking_number,
                'date'            => $order->order_created_at
                    ? $order->order_created_at->format('Y-m-d H:i')
                    : null,
            ];
        });

        return response()->json([
            'data'               => $data,
            'current_page'       => $orders->currentPage(),
            'last_page'          => $orders->lastPage(),
            'total'              => $orders->total(),
            'tab_counts'         => $tabCounts,
            'pending_sub_counts' => (object) [],
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

        $store = VentaSetting::find($storeId);
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

        return response()->json([
            'order_id'          => $order->venta_order_number ?: (string) $order->venta_order_id,
            'status'            => $order->status ?? '',
            'buyer_name'        => $order->customer_name ?? '',
            'total_amount'      => $order->total !== null ? (float) $order->total : null,
            'currency'          => $raw['currency'] ?? 'PHP',
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
        ]);
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
}
