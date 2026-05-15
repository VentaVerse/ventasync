<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Catalog\Order;
use App\Models\Catalog\OrderHistory;
use App\Models\Catalog\OrderStatus;
use App\Models\Catalog\Product;
use App\Services\OrderStockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
            ->with('status')
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
                } elseif ($source === 'opencart') {
                    $query->where('marketplace_source', 'like', 'opencart%');
                } else {
                    $query->where('marketplace_source', $source);
                }
            })
            ->orderBy($sort, $dir)
            ->paginate(25);

        $ocStoreNames = class_exists(\Extensions\opencart\Models\OpenCartSetting::class)
            ? \Extensions\opencart\Models\OpenCartSetting::pluck('store_name', 'id')->toArray()
            : [];

        $orders->getCollection()->transform(function ($order) use ($ocStoreNames) {
            // Resolve first product image
            $firstProduct = $order->products()->first();
            $productImage = null;
            if ($firstProduct && $firstProduct->product_id > 0) {
                $img = Product::where('product_id', $firstProduct->product_id)->value('image');
                if ($img && trim($img) !== '') {
                    $productImage = url('/storage/' . ltrim($img, '/'));
                }
            }

            return [
                'order_id'             => $order->order_id,
                'firstname'            => $order->firstname,
                'lastname'             => $order->lastname,
                'email'                => $order->email,
                'telephone'            => $order->telephone,
                'total'                => (float) $order->total,
                'currency_code'        => $order->currency_code,
                'order_status_id'      => (int) $order->order_status_id,
                'status_name'          => $order->status->name ?? '',
                'date_added'           => $order->date_added,
                'marketplace_source'   => self::resolveSourceLabel($order->marketplace_source ?? '', $ocStoreNames),
                'marketplace_order_id' => $order->marketplace_order_id ?? '',
                'product_image'        => $productImage,
            ];
        });

        return response()->json($orders);
    }

    public function show($id)
    {
        $order = Order::where('order_id', (int) $id)->firstOrFail();
        $products = $order->products()->with('options')->get();
        $totals = $order->totals()->orderBy('sort_order')->get();
        $history = OrderHistory::where('order_id', (int) $id)
            ->with('status')
            ->orderByDesc('date_added')
            ->get();

        // Resolve product images from catalog
        $productImages = $this->resolveProductImages($products);

        $ocStoreNames = class_exists(\Extensions\opencart\Models\OpenCartSetting::class)
            ? \Extensions\opencart\Models\OpenCartSetting::pluck('store_name', 'id')->toArray()
            : [];

        return response()->json([
            'order' => [
                'order_id'           => $order->order_id,
                'firstname'          => $order->firstname,
                'lastname'           => $order->lastname,
                'email'              => $order->email,
                'telephone'          => $order->telephone,
                'comment'            => $order->comment ?? '',
                'total'              => (float) $order->total,
                'currency_code'      => $order->currency_code,
                'order_status_id'    => (int) $order->order_status_id,
                'payment_method'     => $order->payment_method ?? '',
                'shipping_method'    => $order->shipping_method ?? '',
                'shipping_address_1' => $order->shipping_address_1 ?? '',
                'shipping_city'      => $order->shipping_city ?? '',
                'shipping_country'   => $order->shipping_country ?? '',
                'tracking_number'    => $order->tracking_number ?? '',
                'date_added'         => $order->date_added,
                'date_modified'      => $order->date_modified,
                'marketplace_source'   => self::resolveSourceLabel($order->marketplace_source ?? '', $ocStoreNames),
                'marketplace_order_id' => $order->marketplace_order_id ?? '',
            ],
            'products' => $products->map(function ($p) use ($productImages) {
                return [
                    'order_product_id' => $p->order_product_id,
                    'name'     => $p->name,
                    'model'    => $p->model,
                    'quantity' => (int) $p->quantity,
                    'price'    => (float) $p->price,
                    'total'    => (float) $p->total,
                    'image'    => $productImages[$p->order_product_id] ?? null,
                    'options'  => $p->options->map(fn ($o) => [
                        'name'  => $o->name,
                        'value' => $o->value,
                    ]),
                ];
            }),
            'totals' => $totals->map(fn ($t) => [
                'code'  => $t->code,
                'title' => $t->title,
                'value' => (float) $t->value,
            ]),
            'history' => $history->map(fn ($h) => [
                'order_status_id' => (int) $h->order_status_id,
                'status_name'     => $h->status->name ?? '',
                'comment'         => $h->comment ?? '',
                'date_added'      => $h->date_added,
            ]),
        ]);
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

        $user = Auth::user();
        OrderHistory::create([
            'order_id'        => $order->order_id,
            'order_status_id' => $newStatusId,
            'notify'          => 0,
            'comment'         => $request->comment ?? '',
            'date_added'      => now(),
            'user_id'         => $user?->id,
            'user_name'       => $user ? ($user->name ?? $user->username ?? 'User #' . $user->id) : 'API',
        ]);

        if ($oldStatusId !== $newStatusId) {
            OrderStockService::adjustStock($order, $oldStatusId, $newStatusId);
        }

        // FCM notification dispatched centrally by App\Observers\OrderObserver
        // on $order->update() above.

        return response()->json(['message' => 'Order status updated.']);
    }

    public function pendingCount()
    {
        $pendingStatuses = OrderStatus::where(function ($q) {
            $q->where('name', 'like', '%pending%')
              ->orWhere('name', 'like', '%processing%');
        })->pluck('order_status_id');

        $count = Order::whereIn('order_status_id', $pendingStatuses)->count();

        $lazadaPending = 0;
        if (Schema::hasTable('lazada_orders')) {
            $lazadaPending = DB::table('lazada_orders')
                ->whereIn('status', ['pending', 'repacked'])
                ->count();
        }

        $shopeePending = 0;
        if (Schema::hasTable('shopee_orders')) {
            $shopeePending = DB::table('shopee_orders')
                ->whereIn('status', ['READY_TO_SHIP'])
                ->count();
        }

        $tiktokPending = 0;
        if (Schema::hasTable('tiktok_orders')) {
            $tiktokPending = DB::table('tiktok_orders')
                ->whereIn('status', ['AWAITING_SHIPMENT'])
                ->count();
        }

        // Venta pending counts per store
        $ventaPending = [];
        if (Schema::hasTable('venta_orders') && Schema::hasTable('venta_settings')) {
            $enabledStoreIds = DB::table('venta_settings')
                ->where('enabled', true)
                ->pluck('id');

            // Statuses that map to a core "pending" or "processing" status
            $pendingCoreIds = $pendingStatuses; // reuse from above

            if ($enabledStoreIds->isNotEmpty()) {
                $pendingVentaStatuses = DB::table('venta_order_status_map')
                    ->whereIn('venta_setting_id', $enabledStoreIds)
                    ->whereIn('order_status_id', $pendingCoreIds)
                    ->pluck('venta_status_name', 'venta_setting_id')
                    ->groupBy(fn ($val, $key) => $key);

                foreach ($enabledStoreIds as $storeId) {
                    $mappedStatuses = isset($pendingVentaStatuses[$storeId])
                        ? $pendingVentaStatuses[$storeId]->values()->toArray()
                        : [];

                    if (! empty($mappedStatuses)) {
                        $ventaPending[(string) $storeId] = DB::table('venta_orders')
                            ->where('venta_setting_id', $storeId)
                            ->whereIn('status', $mappedStatuses)
                            ->count();
                    } else {
                        // Fallback: count orders with status containing "pending" or "processing"
                        $ventaPending[(string) $storeId] = DB::table('venta_orders')
                            ->where('venta_setting_id', $storeId)
                            ->where(function ($q) {
                                $q->where('status', 'like', '%pending%')
                                    ->orWhere('status', 'like', '%processing%');
                            })
                            ->count();
                    }
                }
            }
        }

        return response()->json([
            'count'          => $count,
            'lazada_pending' => $lazadaPending,
            'shopee_pending' => $shopeePending,
            'tiktok_pending' => $tiktokPending,
            'venta_pending'  => (object) $ventaPending,
        ]);
    }

    public function statuses()
    {
        $statuses = OrderStatus::orderBy('order_status_id')
            ->get(['order_status_id', 'name']);

        return response()->json($statuses);
    }

    /**
     * Resolve "opencart:1" → "Store Name" or return the source as-is.
     */
    private static function resolveSourceLabel(string $source, array $ocStoreNames): string
    {
        if (preg_match('/^opencart:(\d+)$/', $source, $m)) {
            return $ocStoreNames[(int) $m[1]] ?? 'OpenCart';
        }

        return $source;
    }

    /**
     * Build order_product_id → full image URL map.
     */
    private function resolveProductImages($orderProducts): array
    {
        $productIds = $orderProducts
            ->filter(fn ($p) => $p->product_id > 0)
            ->pluck('product_id')
            ->unique()
            ->values()
            ->toArray();

        if (empty($productIds)) {
            return [];
        }

        $images = Product::whereIn('product_id', $productIds)
            ->pluck('image', 'product_id')
            ->toArray();

        $result = [];
        foreach ($orderProducts as $op) {
            $pid = $op->product_id;
            if ($pid > 0 && isset($images[$pid]) && trim($images[$pid]) !== '') {
                $result[$op->order_product_id] = url('/storage/' . ltrim($images[$pid], '/'));
            }
        }

        return $result;
    }
}
