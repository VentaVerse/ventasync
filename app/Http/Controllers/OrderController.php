<?php

namespace App\Http\Controllers;

use App\Models\Catalog\Order;
use App\Models\Catalog\OrderHistory;
use App\Models\Catalog\OrderProduct;
use App\Models\Catalog\OrderStatus;
use App\Models\Catalog\Product;
use App\Models\LazadaOrder;
use App\Models\LazadaProduct;
use App\Models\LazadaProductVariant;
use App\Models\OpenCartSetting;
use App\Models\ShopeeOrder;
use App\Services\ActivityLogger;
use App\Services\FcmService;
use App\Services\OrderStockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OrderController extends Controller
{
    private function currentUser(): array
    {
        $user = Auth::user();
        return [
            'user_id'   => $user?->id,
            'user_name' => $user ? ($user->name ?? $user->username ?? 'User #' . $user->id) : null,
        ];
    }

    public function index(Request $request)
    {
        $q = trim((string) $request->get('q', ''));
        $statusId = (int) $request->get('status', 0);
        $source = trim((string) $request->get('source', ''));

        $sortable = ['order_id', 'date_added', 'total', 'firstname'];
        $sort = in_array($request->get('sort'), $sortable) ? $request->get('sort') : 'date_added';
        $dir = $request->get('dir') === 'asc' ? 'asc' : 'desc';

        $orders = Order::query()
            ->with(['products', 'latestHistory'])
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('firstname', 'like', '%' . $q . '%')
                        ->orWhere('lastname', 'like', '%' . $q . '%')
                        ->orWhere('email', 'like', '%' . $q . '%')
                        ->orWhere('marketplace_order_id', 'like', '%' . $q . '%')
                        ->orWhere('order_id', '=', (int) $q > 0 ? (int) $q : 0);
                });
            })
            ->when($statusId > 0, function ($query) use ($statusId) {
                $query->where('order_status_id', $statusId);
            })
            ->when($source !== '', function ($query) use ($source) {
                if ($source === 'manual') {
                    $query->where('marketplace_source', '');
                } else {
                    $query->where('marketplace_source', $source);
                }
            })
            ->orderBy($sort, $dir)
            ->paginate(50)
            ->withQueryString();

        // Resolve catalog product images (handles product_id=0 via Lazada variant chain)
        $productImages = self::resolveOrderProductImages($orders->getCollection());
        $lazadaImages = self::resolveLazadaImages($orders->getCollection());

        $statuses = OrderStatus::orderBy('order_status_id')->get();

        // Build opencart store ID → name map for the Source column
        $ocStoreNames = OpenCartSetting::pluck('store_name', 'id')->toArray();

        return view('orders.index', compact('orders', 'q', 'statusId', 'statuses', 'source', 'productImages', 'lazadaImages', 'sort', 'dir', 'ocStoreNames'));
    }

    public function opencartIndex(Request $request, int $store)
    {
        $q = trim((string) $request->get('q', ''));
        $statusId = (int) $request->get('status', 0);

        $sortable = ['order_id', 'date_added', 'total', 'firstname'];
        $sort = in_array($request->get('sort'), $sortable) ? $request->get('sort') : 'date_added';
        $dir = $request->get('dir') === 'asc' ? 'asc' : 'desc';

        $orders = Order::query()
            ->with(['products', 'latestHistory'])
            ->where('marketplace_source', 'opencart:' . $store)
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('firstname', 'like', '%' . $q . '%')
                        ->orWhere('lastname', 'like', '%' . $q . '%')
                        ->orWhere('email', 'like', '%' . $q . '%')
                        ->orWhere('marketplace_order_id', 'like', '%' . $q . '%')
                        ->orWhere('order_id', '=', (int) $q > 0 ? (int) $q : 0);
                });
            })
            ->when($statusId > 0, function ($query) use ($statusId) {
                $query->where('order_status_id', $statusId);
            })
            ->orderBy($sort, $dir)
            ->paginate(50)
            ->withQueryString();

        $productImages = self::resolveOrderProductImages($orders->getCollection());
        $lazadaImages = self::resolveLazadaImages($orders->getCollection());
        $statuses = OrderStatus::orderBy('order_status_id')->get();
        $ocStoreNames = OpenCartSetting::pluck('store_name', 'id')->toArray();

        $storeLabel = ($ocStoreNames[$store] ?? '') ?: ('OpenCart #' . $store);

        return view('opencart.orders.index', compact(
            'orders',
            'q',
            'statusId',
            'statuses',
            'productImages',
            'lazadaImages',
            'sort',
            'dir',
            'ocStoreNames',
            'store',
            'storeLabel'
        ));
    }

    public function create()
    {
        $statuses = OrderStatus::orderBy('order_status_id')->get();

        return view('orders.create', compact('statuses'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'firstname'        => 'required|string|max:32',
            'lastname'         => 'nullable|string|max:32',
            'email'            => 'nullable|string|max:96',
            'telephone'        => 'nullable|string|max:32',
            'payment_method'   => 'nullable|string|max:128',
            'shipping_method'  => 'nullable|string',
            'total'            => 'nullable|numeric|min:0',
            'order_status_id'  => 'required|integer|min:0',
            'comment'          => 'nullable|string',
            'currency_code'    => 'nullable|string|max:3',
        ]);

        $now = now();

        $order = Order::create([
            'invoice_no'           => 0,
            'invoice_prefix'       => '',
            'store_id'             => 0,
            'store_name'           => '',
            'store_url'            => '',
            'customer_id'          => 0,
            'customer_group_id'    => 0,
            'firstname'            => $request->firstname,
            'lastname'             => $request->lastname ?? '',
            'email'                => $request->email ?? '',
            'telephone'            => $request->telephone ?? '',
            'fax'                  => '',
            'custom_field'         => '',
            'payment_firstname'    => $request->payment_firstname ?? $request->firstname,
            'payment_lastname'     => $request->payment_lastname ?? ($request->lastname ?? ''),
            'payment_company'      => $request->payment_company ?? '',
            'payment_address_1'    => $request->payment_address_1 ?? '',
            'payment_address_2'    => $request->payment_address_2 ?? '',
            'payment_city'         => $request->payment_city ?? '',
            'payment_postcode'     => $request->payment_postcode ?? '',
            'payment_country'      => $request->payment_country ?? '',
            'payment_country_id'   => 0,
            'payment_zone'         => $request->payment_zone ?? '',
            'payment_zone_id'      => 0,
            'payment_address_format' => '',
            'payment_custom_field' => '',
            'payment_method'       => $request->payment_method ?? '',
            'payment_cost'         => 0,
            'payment_code'         => '',
            'shipping_firstname'   => $request->shipping_firstname ?? $request->firstname,
            'shipping_lastname'    => $request->shipping_lastname ?? ($request->lastname ?? ''),
            'shipping_company'     => $request->shipping_company ?? '',
            'shipping_address_1'   => $request->shipping_address_1 ?? '',
            'shipping_address_2'   => $request->shipping_address_2 ?? '',
            'shipping_city'        => $request->shipping_city ?? '',
            'shipping_postcode'    => $request->shipping_postcode ?? '',
            'shipping_country'     => $request->shipping_country ?? '',
            'shipping_country_id'  => 0,
            'shipping_zone'        => $request->shipping_zone ?? '',
            'shipping_zone_id'     => 0,
            'shipping_address_format' => '',
            'shipping_custom_field' => '',
            'shipping_method'      => $request->shipping_method ?? '',
            'shipping_cost'        => 0,
            'shipping_code'        => '',
            'comment'              => $request->comment ?? '',
            'total'                => $request->total ?? 0,
            'extra_cost'           => 0,
            'order_status_id'      => $request->order_status_id,
            'affiliate_id'         => 0,
            'commission'           => 0,
            'marketing_id'         => 0,
            'tracking'             => '',
            'language_id'          => 1,
            'currency_id'          => 0,
            'currency_code'        => $request->currency_code ?? 'PHP',
            'currency_value'       => 1,
            'ip'                   => $request->ip() ?? '',
            'forwarded_ip'         => '',
            'user_agent'           => '',
            'accept_language'      => '',
            'courier_id'           => 0,
            'tracking_number'      => '',
            'date_added'           => $now,
            'date_modified'        => $now,
            'oe_import'            => 0,
        ]);

        // Add initial history entry
        OrderHistory::create(array_merge($this->currentUser(), [
            'order_id'        => $order->order_id,
            'order_status_id' => $request->order_status_id,
            'notify'          => 0,
            'comment'         => $request->comment ?? '',
            'date_added'      => $now,
        ]));

        // Adjust stock based on new status
        OrderStockService::adjustStock($order, 0, (int) $request->order_status_id);

        ActivityLogger::log('created', 'Order', (int) $order->order_id, '#' . $order->order_id . ' ' . trim($request->firstname . ' ' . $request->lastname));

        try {
            (new FcmService())->notifyIfNeeded(
                (int) $order->order_id,
                0,
                (int) $request->order_status_id,
                trim($request->firstname . ' ' . ($request->lastname ?? '')),
                number_format($request->total ?? 0, 2) . ' ' . ($request->currency_code ?? 'PHP'),
            );
        } catch (\Throwable $e) {
            \Log::warning('FCM notification failed: ' . $e->getMessage());
        }

        return redirect()->route('orders.index')->with('status', 'Order created.');
    }

    public function show($id)
    {
        $order = Order::where('order_id', (int) $id)->firstOrFail();
        $statuses = OrderStatus::orderBy('order_status_id')->get();
        $history = OrderHistory::where('order_id', (int) $id)
            ->orderByDesc('date_added')
            ->get();
        $products = $order->products()->with('options')->get();
        $orderTotals = $order->totals()->orderBy('sort_order')->get();

        // Resolve catalog products (handles product_id=0 via Lazada variant chain)
        $catalogProducts = self::resolveOrderCatalogProducts($products);
        $lazadaImages = self::resolveLazadaImages(collect([$order]));

        // Look up marketplace fees from the source order
        $marketplaceFees = self::resolveMarketplaceFees($order);

        return view('orders.show', compact('order', 'statuses', 'history', 'products', 'orderTotals', 'catalogProducts', 'lazadaImages', 'marketplaceFees'));
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'order_status_id' => 'required|integer|min:1',
            'comment'         => 'nullable|string',
        ]);

        $order = Order::where('order_id', (int) $id)->firstOrFail();
        $oldStatusId = (int) $order->order_status_id;
        $newStatusId = (int) $request->order_status_id;

        $order->update([
            'order_status_id' => $newStatusId,
            'date_modified'   => now(),
        ]);

        OrderHistory::create(array_merge($this->currentUser(), [
            'order_id'        => $order->order_id,
            'order_status_id' => $newStatusId,
            'notify'          => 0,
            'comment'         => $request->comment ?? '',
            'date_added'      => now(),
        ]));

        if ($oldStatusId !== $newStatusId) {
            OrderStockService::adjustStock($order, $oldStatusId, $newStatusId);
        }

        $oldStatusName = OrderStatus::where('order_status_id', $oldStatusId)->value('name') ?? $oldStatusId;
        $newStatusName = OrderStatus::where('order_status_id', $newStatusId)->value('name') ?? $newStatusId;
        ActivityLogger::log(
            'updated',
            'Order',
            (int) $order->order_id,
            '#' . $order->order_id . ' ' . trim($order->firstname . ' ' . $order->lastname),
            ['status' => [(string) $oldStatusName, (string) $newStatusName]]
        );

        try {
            $firstProduct = $order->products()->first();
            $itemName = $firstProduct ? trim($firstProduct->name ?? '') : trim($order->firstname . ' ' . $order->lastname);
            $total = number_format((float) $order->total, 2) . ' ' . ($order->currency_code ?: 'PHP');
            (new FcmService())->notifyIfNeeded((int) $order->order_id, $oldStatusId, $newStatusId, $itemName, $total, $order->marketplace_source ?? '');
        } catch (\Throwable $e) {
            \Log::warning('FCM notification failed: ' . $e->getMessage());
        }

        return redirect()->route('orders.show', $id)->with('status', 'Order status updated.');
    }

    public function edit($id)
    {
        $order = Order::where('order_id', (int) $id)->firstOrFail();
        $statuses = OrderStatus::orderBy('order_status_id')->get();
        $history = OrderHistory::where('order_id', (int) $id)
            ->orderByDesc('date_added')
            ->get();
        $products = $order->products()->with('options')->get();
        $orderTotals = $order->totals()->orderBy('sort_order')->get();

        return view('orders.edit', compact('order', 'statuses', 'history', 'products', 'orderTotals'));
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'firstname'        => 'required|string|max:32',
            'lastname'         => 'nullable|string|max:32',
            'email'            => 'nullable|string|max:96',
            'telephone'        => 'nullable|string|max:32',
            'payment_method'   => 'nullable|string|max:128',
            'shipping_method'  => 'nullable|string',
            'total'            => 'nullable|numeric|min:0',
            'order_status_id'  => 'required|integer|min:0',
            'comment'          => 'nullable|string',
        ]);

        $order = Order::where('order_id', (int) $id)->firstOrFail();
        $oldStatusId = $order->order_status_id;
        $original = $order->getAttributes();

        $order->update([
            'firstname'          => $request->firstname,
            'lastname'           => $request->lastname ?? '',
            'email'              => $request->email ?? '',
            'telephone'          => $request->telephone ?? '',
            'payment_firstname'  => $request->payment_firstname ?? $request->firstname,
            'payment_lastname'   => $request->payment_lastname ?? ($request->lastname ?? ''),
            'payment_company'    => $request->payment_company ?? '',
            'payment_address_1'  => $request->payment_address_1 ?? '',
            'payment_address_2'  => $request->payment_address_2 ?? '',
            'payment_city'       => $request->payment_city ?? '',
            'payment_postcode'   => $request->payment_postcode ?? '',
            'payment_country'    => $request->payment_country ?? '',
            'payment_zone'       => $request->payment_zone ?? '',
            'payment_method'     => $request->payment_method ?? '',
            'shipping_firstname' => $request->shipping_firstname ?? $request->firstname,
            'shipping_lastname'  => $request->shipping_lastname ?? ($request->lastname ?? ''),
            'shipping_company'   => $request->shipping_company ?? '',
            'shipping_address_1' => $request->shipping_address_1 ?? '',
            'shipping_address_2' => $request->shipping_address_2 ?? '',
            'shipping_city'      => $request->shipping_city ?? '',
            'shipping_postcode'  => $request->shipping_postcode ?? '',
            'shipping_country'   => $request->shipping_country ?? '',
            'shipping_zone'      => $request->shipping_zone ?? '',
            'shipping_method'    => $request->shipping_method ?? '',
            'comment'            => $request->comment ?? '',
            'total'              => $request->total ?? 0,
            'order_status_id'    => $request->order_status_id,
            'tracking_number'    => $request->tracking_number ?? '',
            'date_modified'      => now(),
        ]);

        // Add history entry if status changed
        if ((int) $oldStatusId !== (int) $request->order_status_id) {
            OrderHistory::create(array_merge($this->currentUser(), [
                'order_id'        => $order->order_id,
                'order_status_id' => (int) $request->order_status_id,
                'notify'          => 0,
                'comment'         => $request->history_comment ?? '',
                'date_added'      => now(),
            ]));

            // Adjust stock based on status transition
            OrderStockService::adjustStock($order, (int) $oldStatusId, (int) $request->order_status_id);
        }

        $changes = ActivityLogger::diff($original, $order->getAttributes(), [
            'firstname', 'lastname', 'email', 'telephone', 'total',
            'order_status_id', 'tracking_number', 'payment_method', 'shipping_method',
        ]);
        ActivityLogger::log('updated', 'Order', (int) $id, '#' . $id . ' ' . trim($request->firstname . ' ' . ($request->lastname ?? '')), $changes);

        return redirect()->route('orders.edit', $id)->with('status', 'Order updated.');
    }

    public function destroy($id)
    {
        $orderId = (int) $id;
        Order::where('order_id', $orderId)->delete();
        OrderHistory::where('order_id', $orderId)->delete();

        ActivityLogger::log('deleted', 'Order', $orderId, '#' . $orderId);

        return redirect()->route('orders.index')->with('status', 'Order deleted.');
    }

    public function bulkAction(Request $request)
    {
        $ids = $request->input('ids', []);
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($v) => $v > 0)));

        if (empty($ids)) {
            return redirect()->route('orders.index')->with('status', 'No items selected.');
        }

        Order::whereIn('order_id', $ids)->delete();
        OrderHistory::whereIn('order_id', $ids)->delete();

        foreach ($ids as $orderId) {
            ActivityLogger::log('deleted', 'Order', $orderId, '#' . $orderId);
        }

        return redirect()->route('orders.index')->with('status', 'Deleted selected orders.');
    }

    /**
     * Resolve catalog product_id for order products (handles Lazada product_id=0 via variant chain).
     * Returns [ order_product_id => product_id ]
     */
    private static function resolveProductIdMap($orderProducts): array
    {
        $map = [];
        $skusToResolve = [];

        foreach ($orderProducts as $op) {
            if ($op->product_id > 0) {
                $map[$op->order_product_id] = $op->product_id;
            } else {
                $sku = trim((string) $op->model);
                if ($sku !== '') {
                    $skusToResolve[$op->order_product_id] = $sku;
                }
            }
        }

        if ($skusToResolve) {
            $variants = LazadaProductVariant::whereIn('seller_sku', array_values($skusToResolve))
                ->get()
                ->keyBy('seller_sku');

            $lazadaProductIds = $variants->pluck('lazada_product_id')->unique()->values()->all();
            $lazadaProducts = $lazadaProductIds
                ? LazadaProduct::whereIn('id', $lazadaProductIds)->pluck('product_id', 'id')->toArray()
                : [];

            foreach ($skusToResolve as $opId => $sku) {
                $variant = $variants->get($sku);
                if ($variant && isset($lazadaProducts[$variant->lazada_product_id])) {
                    $pid = (int) $lazadaProducts[$variant->lazada_product_id];
                    if ($pid > 0) {
                        $map[$opId] = $pid;
                    }
                }
            }
        }

        return $map;
    }

    /**
     * Build order_product_id → image path map for a collection of orders.
     */
    private static function resolveOrderProductImages($orders): array
    {
        $allProducts = $orders->flatMap(fn ($o) => $o->products);
        $idMap = self::resolveProductIdMap($allProducts);

        $productIds = array_unique(array_values($idMap));
        $images = $productIds
            ? Product::whereIn('product_id', $productIds)->pluck('image', 'product_id')->toArray()
            : [];

        // Re-key by order_product_id so views can look up directly
        $result = [];
        foreach ($idMap as $opId => $pid) {
            if (isset($images[$pid]) && trim($images[$pid]) !== '') {
                $result[$opId] = $images[$pid];
            }
        }

        return $result;
    }

    /**
     * Build catalog product_id → Product map for an order's products (show page).
     */
    private static function resolveOrderCatalogProducts($orderProducts): array
    {
        $idMap = self::resolveProductIdMap($orderProducts);

        $productIds = array_unique(array_values($idMap));
        $catalogProducts = $productIds
            ? Product::whereIn('product_id', $productIds)->get()->keyBy('product_id')->toArray()
            : [];

        // Re-key by order_product_id so views can look up by order product
        $result = [];
        foreach ($idMap as $opId => $pid) {
            if (isset($catalogProducts[$pid])) {
                $result[$opId] = (object) $catalogProducts[$pid];
            }
        }

        return $result;
    }

    /**
     * Build order_product_id → Lazada image URL map for Lazada orders.
     */
    private static function resolveLazadaImages($orders): array
    {
        $lazadaOrderIds = [];
        foreach ($orders as $o) {
            if ($o->marketplace_source === 'lazada') {
                $lazadaOrderIds[] = $o->order_id;
            }
        }

        if (! $lazadaOrderIds) {
            return [];
        }

        $lazadaOrders = LazadaOrder::whereIn('catalog_order_id', $lazadaOrderIds)
            ->with('products')
            ->get()
            ->keyBy('catalog_order_id');

        $result = [];
        foreach ($orders as $o) {
            if ($o->marketplace_source !== 'lazada') {
                continue;
            }
            $lzOrder = $lazadaOrders->get($o->order_id);
            if (! $lzOrder) {
                continue;
            }
            $lzProducts = $lzOrder->products->keyBy('sku');
            foreach ($o->products as $op) {
                $sku = trim((string) $op->model);
                $lzProduct = $sku !== '' ? $lzProducts->get($sku) : null;
                $image = $lzProduct ? trim((string) ($lzProduct->image ?? '')) : '';
                if ($image !== '') {
                    $result[$op->order_product_id] = $image;
                }
            }
        }

        return $result;
    }

    /**
     * Resolve marketplace fees from the source Lazada/Shopee order.
     * Returns an array with individual fee components and a total.
     */
    private static function resolveMarketplaceFees(Order $order): array
    {
        $source = (string) $order->marketplace_source;
        $mktOrderId = (string) $order->marketplace_order_id;

        if ($source === '' || $mktOrderId === '') {
            return ['total' => 0, 'items' => [], 'source' => ''];
        }

        if ($source === 'lazada') {
            $lzOrder = LazadaOrder::where('order_id', $mktOrderId)->first();
            if (!$lzOrder || empty($lzOrder->fees)) {
                return ['total' => 0, 'items' => [], 'source' => 'lazada'];
            }

            $fees = is_array($lzOrder->fees) ? $lzOrder->fees : [];
            $items = [];
            $total = 0;

            $commission = abs((float) ($fees['commission'] ?? 0));
            $paymentFee = abs((float) ($fees['payment_fee'] ?? 0));
            $shippingCost = abs((float) ($fees['shipping_service_cost'] ?? 0));

            if ($commission > 0) { $items[] = ['label' => 'Commission', 'amount' => $commission]; $total += $commission; }
            if ($paymentFee > 0) { $items[] = ['label' => 'Payment Fee', 'amount' => $paymentFee]; $total += $paymentFee; }
            if ($shippingCost > 0) { $items[] = ['label' => 'Shipping Fee', 'amount' => $shippingCost]; $total += $shippingCost; }

            // Other fees (stored as key=>value map)
            $otherFees = $fees['other_fees'] ?? [];
            if (is_array($otherFees)) {
                foreach ($otherFees as $label => $amount) {
                    $amt = abs((float) $amount);
                    if ($amt > 0) { $items[] = ['label' => $label, 'amount' => $amt]; $total += $amt; }
                }
            }

            return ['total' => round($total, 2), 'items' => $items, 'source' => 'lazada'];
        }

        if ($source === 'shopee') {
            $spOrder = ShopeeOrder::where('order_sn', $mktOrderId)->first();
            if (!$spOrder || empty($spOrder->fees)) {
                return ['total' => 0, 'items' => [], 'source' => 'shopee'];
            }

            $feesRaw = is_array($spOrder->fees) ? $spOrder->fees : [];
            $income = $feesRaw['order_income'] ?? $feesRaw;
            if (!is_array($income)) {
                return ['total' => 0, 'items' => [], 'source' => 'shopee'];
            }

            $items = [];
            $total = 0;

            $commission = abs((float) ($income['commission_fee'] ?? 0));
            $serviceFee = abs((float) ($income['service_fee'] ?? 0));
            $txnFee = abs((float) ($income['seller_transaction_fee'] ?? 0));
            $withholdingTax = abs((float) ($income['withholding_tax'] ?? 0));

            if ($commission > 0) { $items[] = ['label' => 'Commission', 'amount' => $commission]; $total += $commission; }
            if ($serviceFee > 0) { $items[] = ['label' => 'Service Fee', 'amount' => $serviceFee]; $total += $serviceFee; }
            if ($txnFee > 0) { $items[] = ['label' => 'Transaction Fee', 'amount' => $txnFee]; $total += $txnFee; }
            if ($withholdingTax > 0) { $items[] = ['label' => 'Withholding Tax', 'amount' => $withholdingTax]; $total += $withholdingTax; }

            return ['total' => round($total, 2), 'items' => $items, 'source' => 'shopee'];
        }

        // OpenCart: use payment_cost + extra_cost from order table
        if (str_starts_with($source, 'opencart:')) {
            $items = [];
            $total = 0;
            $paymentCost = (float) ($order->payment_cost ?? 0);
            $extraCost = (float) ($order->extra_cost ?? 0);

            if ($paymentCost > 0) { $items[] = ['label' => 'Payment Fee', 'amount' => $paymentCost]; $total += $paymentCost; }
            if ($extraCost > 0) { $items[] = ['label' => 'Extra Cost', 'amount' => $extraCost]; $total += $extraCost; }

            return ['total' => round($total, 2), 'items' => $items, 'source' => $source];
        }

        return ['total' => 0, 'items' => [], 'source' => $source];
    }
}
