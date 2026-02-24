<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LazadaOrder;
use App\Models\LazadaSetting;
use App\Models\ShopeeOrder;
use App\Models\ShopeeSetting;
use Illuminate\Http\Request;

class MarketplaceOrderController extends Controller
{
    private const LAZADA_TAB_STATUS_MAP = [
        'UNPAID'              => ['unpaid'],
        'PENDING'             => ['pending', 'packed', 'ready_to_ship'],
        'SHIPPING'            => ['shipped'],
        'DELIVERED_COMPLETED' => ['delivered'],
        'CANCELLED'           => ['canceled', 'cancelled'],
        'RETURN'              => ['returned'],
    ];

    private const SHOPEE_TAB_STATUS_MAP = [
        'UNPAID'              => ['UNPAID'],
        'PENDING'             => ['READY_TO_SHIP', 'PROCESSED'],
        'SHIPPING'            => ['SHIPPED'],
        'DELIVERED_COMPLETED' => ['COMPLETED'],
        'CANCELLED'           => ['CANCELLED', 'IN_CANCEL'],
        'RETURN'              => ['AWAITING_RETURN', 'IN_RETURN', 'RETURNED'],
    ];

    private const ALLOWED_TABS = [
        'ALL', 'UNPAID', 'PENDING', 'SHIPPING',
        'DELIVERED_COMPLETED', 'CANCELLED', 'RETURN',
    ];

    public function index(Request $request, string $platform)
    {
        if (!in_array($platform, ['lazada', 'shopee'], true)) {
            return response()->json(['error' => 'Invalid platform'], 404);
        }

        $tab = strtoupper((string) $request->query('tab', 'ALL'));
        if (!in_array($tab, self::ALLOWED_TABS, true)) {
            $tab = 'ALL';
        }

        $search = trim((string) $request->query('q', ''));

        $isLazada = $platform === 'lazada';
        $tabStatusMap = $isLazada ? self::LAZADA_TAB_STATUS_MAP : self::SHOPEE_TAB_STATUS_MAP;

        // Determine region
        if ($isLazada) {
            $setting = LazadaSetting::query()->first();
        } else {
            $setting = ShopeeSetting::query()->first();
        }
        $region = $setting->region ?? 'ph';

        // Build query
        $modelClass = $isLazada ? LazadaOrder::class : ShopeeOrder::class;
        $query = $modelClass::query()->where('region', $region);
        $baseCountQuery = $modelClass::query()->where('region', $region);

        // Tab filter
        if ($tab !== 'ALL') {
            $statuses = $tabStatusMap[$tab] ?? [];
            if (!empty($statuses)) {
                $query->whereIn('status', $statuses);
            }
        }

        // Search filter
        if ($search !== '') {
            $like = '%' . $search . '%';
            if ($isLazada) {
                $query->where(function ($q) use ($like) {
                    $q->where('order_id', 'like', $like)
                        ->orWhere('raw->customer_first_name', 'like', $like)
                        ->orWhere('raw->customer_name', 'like', $like)
                        ->orWhere('raw->buyer_name', 'like', $like);
                });
            } else {
                $query->where(function ($q) use ($like) {
                    $q->where('order_sn', 'like', $like)
                        ->orWhere('raw->buyer_username', 'like', $like)
                        ->orWhere('raw->buyer_user_name', 'like', $like);
                });
            }
        }

        // Tab counts
        $tabCounts = ['ALL' => (clone $baseCountQuery)->count()];
        foreach ($tabStatusMap as $k => $statuses) {
            $tabCounts[$k] = !empty($statuses)
                ? (clone $baseCountQuery)->whereIn('status', $statuses)->count()
                : 0;
        }

        // Paginate
        $orders = $query
            ->orderByDesc('order_created_at')
            ->orderByDesc('created_at')
            ->paginate(20);

        // Transform
        $data = $orders->map(function ($order) use ($isLazada) {
            $raw = $order->raw ?? [];

            $firstProduct = $order->products()->orderBy('id')->first();
            $productRaw = $firstProduct?->raw ?? [];
            $productImage = $firstProduct?->image
                ?? ($productRaw['image_info']['image_url'] ?? null)
                ?? ($productRaw['product_main_image'] ?? null);

            if ($isLazada) {
                $orderId = $order->order_id;
                $buyerName = $raw['customer_first_name']
                    ?? $raw['customer_name']
                    ?? $raw['buyer_name']
                    ?? '';
                $totalAmount = isset($raw['price']) ? (float) $raw['price'] : null;
                $currency = $raw['currency'] ?? '₱';
                $itemsCount = $order->products()->count();
                $paymentMethod = $raw['payment_method'] ?? null;
                $trackingNumber = $raw['tracking_number'] ?? null;
            } else {
                $orderId = $order->order_sn;
                $buyerName = $raw['buyer_username']
                    ?? $raw['buyer_user_name']
                    ?? '';
                $totalAmount = isset($raw['total_amount']) ? (float) $raw['total_amount'] : null;
                $currency = $raw['currency'] ?? '₱';
                $itemsCount = $order->products()->count();
                $paymentMethod = $raw['payment_method'] ?? null;
                $trackingNumber = $raw['tracking_number'] ?? null;
            }

            return [
                'id'              => $order->id,
                'order_id'        => (string) $orderId,
                'status'          => $order->status,
                'buyer_name'      => $buyerName,
                'total_amount'    => $totalAmount,
                'currency'        => $currency,
                'items_count'     => $itemsCount,
                'product_image'   => $productImage ?: null,
                'payment_method'  => $paymentMethod,
                'tracking_number' => $trackingNumber,
                'date'            => $order->order_created_at
                    ? $order->order_created_at->format('Y-m-d H:i')
                    : null,
            ];
        });

        return response()->json([
            'data'         => $data,
            'current_page' => $orders->currentPage(),
            'last_page'    => $orders->lastPage(),
            'total'        => $orders->total(),
            'tab_counts'   => $tabCounts,
        ]);
    }

    public function show(string $platform, int $id)
    {
        if (!in_array($platform, ['lazada', 'shopee'], true)) {
            return response()->json(['error' => 'Invalid platform'], 404);
        }

        $isLazada = $platform === 'lazada';
        $modelClass = $isLazada ? LazadaOrder::class : ShopeeOrder::class;
        $order = $modelClass::with('products')->find($id);

        if (!$order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        $raw = $order->raw ?? [];

        if ($isLazada) {
            $detail = $raw['_detail'] ?? $raw;
            $orderId = (string) $order->order_id;
            $buyerName = $detail['customer_first_name']
                ?? $detail['customer_name']
                ?? $detail['buyer_name']
                ?? '';
            $totalAmount = isset($detail['price']) ? (float) $detail['price'] : null;
            $currency = $detail['currency'] ?? '₱';
            $paymentMethod = $detail['payment_method'] ?? '';
            $shippingProvider = $detail['shipping_provider'] ?? '';
            $trackingNumber = $detail['tracking_code'] ?? $detail['tracking_number'] ?? '';

            $address = $detail['address_shipping'] ?? [];
            $shippingName = trim(($address['first_name'] ?? '') . ' ' . ($address['last_name'] ?? ''));
            $shippingPhone = $address['phone'] ?? $address['phone2'] ?? '';
            $shippingAddress = trim(implode(', ', array_filter([
                $address['address1'] ?? '',
                $address['address2'] ?? '',
                $address['address3'] ?? '',
                $address['city'] ?? '',
                $address['post_code'] ?? '',
            ])));

            $products = $order->products
                ->groupBy(fn ($p) => ($p->sku ?? '') . '|' . ($p->variation ?? ''))
                ->map(function ($group) {
                    $first = $group->first();
                    $pRaw = $first->raw ?? [];
                    return [
                        'name'      => $first->name,
                        'sku'       => $first->sku ?? '',
                        'variation' => $first->variation ?? '',
                        'quantity'  => $group->sum('quantity'),
                        'price'     => isset($pRaw['item_price']) ? (float) $pRaw['item_price'] : 0,
                        'image'     => $first->image ?? $pRaw['product_main_image'] ?? '',
                    ];
                });
        } else {
            $orderId = $order->order_sn;
            $buyerName = $raw['buyer_username'] ?? $raw['buyer_user_name'] ?? '';
            $totalAmount = isset($raw['total_amount']) ? (float) $raw['total_amount'] : null;
            $currency = $raw['currency'] ?? '₱';
            $paymentMethod = $raw['payment_method'] ?? '';
            $shippingProvider = $raw['shipping_carrier'] ?? $raw['checkout_shipping_carrier'] ?? '';
            $trackingNumber = $raw['tracking_no'] ?? $raw['tracking_number'] ?? '';

            $addr = $raw['recipient_address'] ?? $raw['shipping_address'] ?? [];
            $shippingName = $addr['name'] ?? '';
            $shippingPhone = $addr['phone'] ?? '';
            $shippingAddress = $addr['full_address']
                ?? trim(implode(', ', array_filter([
                    $addr['address'] ?? '',
                    $addr['city'] ?? '',
                    $addr['state'] ?? '',
                    $addr['zipcode'] ?? '',
                ])));

            $products = $order->products->map(function ($p) {
                $pRaw = $p->raw ?? [];
                $imageUrl = $p->image
                    ?? $pRaw['image_info']['image_url']
                    ?? '';
                return [
                    'name'      => $p->name,
                    'sku'       => $p->sku ?? '',
                    'variation' => $p->variation ?? '',
                    'quantity'  => $p->quantity,
                    'price'     => $p->price ?? 0,
                    'image'     => $imageUrl,
                ];
            });
        }

        return response()->json([
            'order_id'          => $orderId,
            'status'            => $order->status,
            'buyer_name'        => $buyerName,
            'total_amount'      => $totalAmount,
            'currency'          => $currency,
            'payment_method'    => $paymentMethod,
            'shipping_provider' => $shippingProvider,
            'tracking_number'   => $trackingNumber,
            'shipping_name'     => $shippingName,
            'shipping_phone'    => $shippingPhone,
            'shipping_address'  => $shippingAddress,
            'date'              => $order->order_created_at
                ? $order->order_created_at->format('Y-m-d H:i')
                : null,
            'products'          => $products->values(),
        ]);
    }
}
