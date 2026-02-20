<?php

namespace App\Http\Controllers;

use App\Models\Catalog\Order;
use App\Models\Catalog\OrderHistory;
use App\Models\Catalog\OrderProduct;
use App\Models\Catalog\OrderStatus;
use App\Models\Catalog\Product;
use App\Models\LazadaProduct;
use App\Models\LazadaProductVariant;
use App\Models\OpenCartSetting;
use App\Services\OrderStockService;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->get('q', ''));
        $statusId = (int) $request->get('status', 0);
        $source = trim((string) $request->get('source', ''));

        $sortable = ['order_id', 'date_added', 'total', 'firstname'];
        $sort = in_array($request->get('sort'), $sortable) ? $request->get('sort') : 'date_added';
        $dir = $request->get('dir') === 'asc' ? 'asc' : 'desc';

        $orders = Order::query()
            ->with('products')
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

        $statuses = OrderStatus::orderBy('order_status_id')->get();

        // Build opencart store ID → name map for the Source column
        $ocStoreNames = OpenCartSetting::pluck('store_name', 'id')->toArray();

        return view('orders.index', compact('orders', 'q', 'statusId', 'statuses', 'source', 'productImages', 'sort', 'dir', 'ocStoreNames'));
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
        OrderHistory::create([
            'order_id'        => $order->order_id,
            'order_status_id' => $request->order_status_id,
            'notify'          => 0,
            'comment'         => $request->comment ?? '',
            'date_added'      => $now,
        ]);

        // Adjust stock based on new status
        OrderStockService::adjustStock($order, 0, (int) $request->order_status_id);

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

        return view('orders.show', compact('order', 'statuses', 'history', 'products', 'orderTotals', 'catalogProducts'));
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

        OrderHistory::create([
            'order_id'        => $order->order_id,
            'order_status_id' => $newStatusId,
            'notify'          => 0,
            'comment'         => $request->comment ?? '',
            'date_added'      => now(),
        ]);

        if ($oldStatusId !== $newStatusId) {
            OrderStockService::adjustStock($order, $oldStatusId, $newStatusId);
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
            OrderHistory::create([
                'order_id'        => $order->order_id,
                'order_status_id' => (int) $request->order_status_id,
                'notify'          => 0,
                'comment'         => $request->history_comment ?? '',
                'date_added'      => now(),
            ]);

            // Adjust stock based on status transition
            OrderStockService::adjustStock($order, (int) $oldStatusId, (int) $request->order_status_id);
        }

        return redirect()->route('orders.edit', $id)->with('status', 'Order updated.');
    }

    public function destroy($id)
    {
        $orderId = (int) $id;
        Order::where('order_id', $orderId)->delete();
        OrderHistory::where('order_id', $orderId)->delete();

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
}
