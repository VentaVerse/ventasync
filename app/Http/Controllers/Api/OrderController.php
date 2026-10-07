<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Catalog\Order;
use App\Models\Catalog\OrderHistory;
use App\Models\Catalog\OrderStatus;
use App\Models\Catalog\Product;
use App\Services\ActivityLogger;
use App\Support\Fulfilment\AppFulfilment;
use App\Services\OrderStockService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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

        $defaultCode = Money::defaultCode();

        $orders->getCollection()->transform(function ($order) use ($ocStoreNames, $defaultCode) {
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
                'currency_code'        => $defaultCode,
                'transaction_currency' => $order->foreign_total !== null ? $order->currency_code : null,
                'transaction_total'    => $order->foreign_total !== null ? (float) $order->foreign_total : null,
                'order_status_id'      => (int) $order->order_status_id,
                'status_name'          => $order->status->name ?? '',
                'date_added'           => $order->date_added,
                'marketplace_source'   => self::resolveSourceLabel($order->marketplace_source ?? '', $ocStoreNames),
                'marketplace_order_id' => $order->marketplace_order_id ?? '',
                'store_id'             => self::storeIdOf($order->marketplace_source ?? '', $order->store_id),
                'store_name'           => (string) ($order->store_name ?? ''),
                'product_image'        => $productImage,
                'fulfilment'           => self::fulfilment($order),
            ];
        });

        return response()->json($orders);
    }

    public function show($id)
    {
        return response()->json($this->showPayload(Order::where('order_id', (int) $id)->firstOrFail()));
    }

    private function showPayload(Order $order): array
    {
        $products = $order->products()->with('options')->get();
        $totals = $order->totals()->orderBy('sort_order')->get();
        $history = OrderHistory::where('order_id', (int) $order->order_id)
            ->with('status')
            ->orderByDesc('date_added')
            ->get();

        $productImages = $this->resolveProductImages($products);

        $ocStoreNames = class_exists(\Extensions\opencart\Models\OpenCartSetting::class)
            ? \Extensions\opencart\Models\OpenCartSetting::pluck('store_name', 'id')->toArray()
            : [];

        return [
            'order' => [
                'order_id'           => $order->order_id,
                'firstname'          => $order->firstname,
                'lastname'           => $order->lastname,
                'email'              => $order->email,
                'telephone'          => $order->telephone,
                'comment'            => $order->comment ?? '',
                'total'              => (float) $order->total,
                'currency_code'      => Money::defaultCode(),
                'transaction_currency' => $order->foreign_total !== null ? $order->currency_code : null,
                'transaction_total'    => $order->foreign_total !== null ? (float) $order->foreign_total : null,
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
                'store_id'             => self::storeIdOf($order->marketplace_source ?? '', $order->store_id),
                'store_name'           => (string) ($order->store_name ?? ''),
                'fulfilment'           => self::fulfilment($order),
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
        ];
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'order_status_id' => 'required|integer|min:1',
            'comment'         => 'nullable|string',
        ]);

        $order = Order::where('order_id', (int) $id)->firstOrFail();
        $this->changeStatus($order, (int) $request->order_status_id, (string) ($request->comment ?? ''));

        return response()->json(['message' => 'Order status updated.']);
    }

    public function ship(Request $request, $id)
    {
        $pfx = (string) config('catalog.prefix');
        $data = $request->validate([
            'order_status_id' => ['required', 'integer', Rule::exists($pfx . 'order_status', 'order_status_id')],
            'tracking_number' => ['required', 'string', 'max:32'],
            'courier_name'    => ['nullable', 'string', 'max:64'],
            'comment'         => ['nullable', 'string', 'max:1000'],
        ]);

        $order = Order::where('order_id', (int) $id)->firstOrFail();
        if ((string) ($order->marketplace_source ?? '') !== '') {
            return AppFulfilment::refused('This order ships through its store.');
        }
        if (trim((string) $order->tracking_number) !== '') {
            return AppFulfilment::alreadyDone('This order is already shipped.', $this->showPayload($order));
        }

        $tracking = trim($data['tracking_number']);
        $courier = trim((string) ($data['courier_name'] ?? ''));
        $comment = implode("\n", array_filter([
            'Shipped' . ($courier !== '' ? ' with ' . $courier : '') . ', tracking ' . $tracking . '.',
            trim((string) ($data['comment'] ?? '')),
        ]));

        DB::transaction(function () use ($order, $tracking, $data, $comment) {
            $order->tracking_number = $tracking;
            $order->save();
            $this->changeStatus($order, (int) $data['order_status_id'], $comment);
        });

        return AppFulfilment::ok('Shipped.', $this->showPayload($order->fresh()));
    }

    private function changeStatus(Order $order, int $newStatusId, string $comment): void
    {
        $oldStatusId = (int) $order->order_status_id;

        $order->update([
            'order_status_id' => $newStatusId,
            'date_modified'   => now(),
        ]);

        $user = Auth::user();
        OrderHistory::create([
            'order_id'        => $order->order_id,
            'order_status_id' => $newStatusId,
            'notify'          => 0,
            'comment'         => $comment,
            'date_added'      => now(),
            'user_id'         => $user?->id,
            'user_name'       => $user ? ($user->name ?? $user->username ?? 'User #' . $user->id) : 'API',
        ]);

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
    }

    private static function fulfilment(Order $order): array
    {
        if ((string) ($order->marketplace_source ?? '') !== '') {
            return AppFulfilment::block('sales', (int) $order->order_id, 'other', []);
        }

        $shipped = trim((string) $order->tracking_number) !== '';

        return AppFulfilment::block('sales', (int) $order->order_id, $shipped ? 'shipped' : 'to_pack', $shipped ? [] : ['mark_shipped']);
    }

    public function pendingCount(Request $request)
    {
        $toPack = \App\Support\FulfilmentBadge::byStore($request->user());

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

        $ventaCartPending = [];
        if (Schema::hasTable('ventacart_orders') && Schema::hasTable('ventacart_settings')) {
            $enabledStoreIds = DB::table('ventacart_settings')
                ->where('enabled', true)
                ->pluck('id');

            $pendingCoreIds = $pendingStatuses;

            if ($enabledStoreIds->isNotEmpty()) {
                $pendingVentaStatuses = DB::table('ventacart_order_status_map')
                    ->whereIn('ventacart_setting_id', $enabledStoreIds)
                    ->whereIn('order_status_id', $pendingCoreIds)
                    ->pluck('ventacart_status_name', 'ventacart_setting_id')
                    ->groupBy(fn ($val, $key) => $key);

                foreach ($enabledStoreIds as $storeId) {
                    $mappedStatuses = isset($pendingVentaStatuses[$storeId])
                        ? $pendingVentaStatuses[$storeId]->values()->toArray()
                        : [];

                    if (! empty($mappedStatuses)) {
                        $ventaCartPending[(string) $storeId] = DB::table('ventacart_orders')
                            ->where('ventacart_setting_id', $storeId)
                            ->whereIn('status', $mappedStatuses)
                            ->count();
                    } else {
                        $ventaCartPending[(string) $storeId] = DB::table('ventacart_orders')
                            ->where('ventacart_setting_id', $storeId)
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
            'ventacart_pending'  => (object) $ventaCartPending,
            'lazada_pending_by_store' => $this->waitingByStore('lazada_orders', 'lazada_settings', 'lazada_setting_id', ['pending', 'repacked']),
            'shopee_pending_by_store' => $this->waitingByStore('shopee_orders', 'shopee_settings', 'shopee_setting_id', ['READY_TO_SHIP']),
            'tiktok_pending_by_store' => $this->waitingByStore('tiktok_orders', 'tiktok_settings', 'tiktok_setting_id', ['AWAITING_SHIPMENT']),
            'to_pack_by_channel' => (object) $toPack,
            'to_pack_total' => array_sum($toPack),
        ]);
    }

    private function waitingByStore(string $orders, string $stores, string $column, array $statuses): object
    {
        if (! Schema::hasTable($orders) || ! Schema::hasTable($stores)) {
            return (object) [];
        }

        $counts = DB::table($orders)->whereIn('status', $statuses)
            ->groupBy($column)->pluck(DB::raw('COUNT(*)'), $column);
        $out = [];
        foreach (DB::table($stores)->where('enabled', true)->orderBy('id')->pluck('id') as $id) {
            $out[(string) $id] = (int) ($counts[$id] ?? 0);
        }

        return (object) $out;
    }

    public function statuses()
    {
        $statuses = OrderStatus::orderBy('order_status_id')
            ->get(['order_status_id', 'name']);

        return response()->json($statuses);
    }

    private static function storeIdOf(string $source, $storeId): ?int
    {
        if ($source === '') {
            return null;
        }
        if (preg_match('/:(\d+)$/', $source, $m)) {
            return (int) $m[1];
        }

        return $storeId !== null ? (int) $storeId : null;
    }

    private static function resolveSourceLabel(string $source, array $ocStoreNames): string
    {
        if (preg_match('/^opencart:(\d+)$/', $source, $m)) {
            return $ocStoreNames[(int) $m[1]] ?? 'OpenCart';
        }

        return $source;
    }

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
