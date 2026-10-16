<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Catalog\Order;
use App\Models\Catalog\OrderHistory;
use App\Models\Catalog\OrderStatus;
use App\Services\ActivityLogger;
use App\Support\Fulfillment\AppFulfillment;
use App\Services\OrderStockService;
use App\Support\LineImage;
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
                        ->orWhere('channel_order_id', 'like', '%' . $q . '%')
                        ->orWhere('order_id', '=', (int) $q > 0 ? (int) $q : 0);
                });
            })
            ->when($statusId > 0, function ($query) use ($statusId) {
                $query->where('order_status_id', $statusId);
            })
            ->when($source !== '', function ($query) use ($source) {
                if ($source === 'manual') {
                    $query->where('channel', '');
                } elseif ($source === 'opencart') {
                    $query->where('channel', 'like', 'opencart%');
                } else {
                    $query->where('channel', $source);
                }
            })
            ->orderBy($sort, $dir)
            ->paginate(25);

        $openCartStoreNames = class_exists(\Extensions\OpenCart\Models\OpenCartStore::class)
            ? \Extensions\OpenCart\Models\OpenCartStore::pluck('store_name', 'id')->toArray()
            : [];

        $defaultCode = Money::defaultCode();

        $orders->getCollection()->transform(function ($order) use ($openCartStoreNames, $defaultCode) {
            $firstProduct = $order->products()->first();
            $productImage = $firstProduct
                ? LineImage::url($firstProduct->sku, null, (int) $firstProduct->product_id ?: null)
                : null;

            return [
                'order_id'             => $order->order_id,
                'firstname'            => $order->firstname,
                'lastname'             => $order->lastname,
                'email'                => $order->email,
                'telephone'            => $order->telephone,
                'total'                => (float) $order->total,
                'currency_code'        => $order->foreign_total === null && (string) $order->currency_code !== '' ? (string) $order->currency_code : $defaultCode,
                'transaction_currency' => $order->foreign_total !== null ? $order->currency_code : null,
                'transaction_total'    => $order->foreign_total !== null ? (float) $order->foreign_total : null,
                'order_status_id'      => (int) $order->order_status_id,
                'status_name'          => $order->status->name ?? '',
                'date_added'           => $order->date_added,
                'channel'              => self::resolveSourceLabel($order->channel ?? '', $openCartStoreNames),
                'channel_order_id' => $order->channel_order_id ?? '',
                'marketplace_order_id' => $order->channel_order_id ?? '',
                'store_id'             => self::storeIdOf($order->channel ?? '', $order->store_id),
                'store_name'           => (string) ($order->store_name ?? ''),
                'product_image'        => $productImage,
                'fulfillment'           => self::fulfillment($order),
                'fulfilment'            => self::fulfillment($order),
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
        $products = $order->products()->with('variations')->get();
        $totals = $order->totals()->orderBy('sort_order')->get();
        $history = OrderHistory::where('order_id', (int) $order->order_id)
            ->with('status')
            ->orderByDesc('date_added')
            ->get();

        $productImages = $this->resolveProductImages($products);

        $openCartStoreNames = class_exists(\Extensions\OpenCart\Models\OpenCartStore::class)
            ? \Extensions\OpenCart\Models\OpenCartStore::pluck('store_name', 'id')->toArray()
            : [];

        return [
            'order' => [
                'order_id'           => $order->order_id,
                'firstname'          => $order->firstname,
                'lastname'           => $order->lastname,
                'email'              => $order->email,
                'telephone'          => $order->telephone,
                'customer_note'      => $order->customer_note ?? '',
                'comment'            => $order->customer_note ?? '',
                'total'              => (float) $order->total,
                'currency_code'      => $order->foreign_total === null && (string) $order->currency_code !== '' ? (string) $order->currency_code : Money::defaultCode(),
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
                'channel'              => self::resolveSourceLabel($order->channel ?? '', $openCartStoreNames),
                'channel_order_id' => $order->channel_order_id ?? '',
                'marketplace_order_id' => $order->channel_order_id ?? '',
                'store_id'             => self::storeIdOf($order->channel ?? '', $order->store_id),
                'store_name'           => (string) ($order->store_name ?? ''),
                'fulfillment'           => self::fulfillment($order),
                'fulfilment'            => self::fulfillment($order),
            ],
            'products' => $products->map(function ($p) use ($productImages) {
                return [
                    'order_product_id' => $p->order_product_id,
                    'name'     => $p->name,
                    'model'    => $p->sku,
                    'sku'      => $p->sku,
                    'quantity' => (int) $p->quantity,
                    'price'    => (float) $p->price,
                    'total'    => (float) $p->total,
                    'image'    => $productImages[$p->order_product_id] ?? null,
                    'variations' => $p->variations->map(fn ($o) => [
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
                'note'            => $h->note ?? '',
                'comment'         => $h->note ?? '',
                'date_added'      => $h->date_added,
            ]),
        ];
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'order_status_id' => 'required|integer|min:1',
            'note'            => 'nullable|string',
            'comment'         => 'nullable|string',
        ]);

        $order = Order::where('order_id', (int) $id)->firstOrFail();
        $this->changeStatus($order, (int) $request->order_status_id, (string) ($request->note ?? $request->comment ?? ''));

        return response()->json(['message' => 'Order status updated.']);
    }

    public function ship(Request $request, $id)
    {
        $data = $request->validate([
            'order_status_id' => ['required', 'integer', Rule::exists('order_statuses', 'order_status_id')],
            'tracking_number' => ['required', 'string', 'max:32'],
            'courier_name'    => ['nullable', 'string', 'max:64'],
            'note'            => ['nullable', 'string', 'max:1000'],
            'comment'         => ['nullable', 'string', 'max:1000'],
        ]);

        $order = Order::where('order_id', (int) $id)->firstOrFail();
        if ((string) ($order->channel ?? '') !== '') {
            return AppFulfillment::refused('This order ships through its store.');
        }
        if (trim((string) $order->tracking_number) !== '') {
            return AppFulfillment::alreadyDone('This order is already shipped.', $this->showPayload($order));
        }

        $tracking = trim($data['tracking_number']);
        $courier = trim((string) ($data['courier_name'] ?? ''));
        $note = implode("\n", array_filter([
            'Shipped' . ($courier !== '' ? ' with ' . $courier : '') . ', tracking ' . $tracking . '.',
            trim((string) ($data['note'] ?? $data['comment'] ?? '')),
        ]));

        DB::transaction(function () use ($order, $tracking, $data, $note) {
            $order->tracking_number = $tracking;
            $order->save();
            $this->changeStatus($order, (int) $data['order_status_id'], $note);
        });

        return AppFulfillment::ok('Shipped.', $this->showPayload($order->fresh()));
    }

    private function changeStatus(Order $order, int $newStatusId, string $note): void
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
            'note'            => $note,
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

    private static function fulfillment(Order $order): array
    {
        if ((string) ($order->channel ?? '') !== '') {
            return AppFulfillment::block('sales', (int) $order->order_id, 'other', []);
        }

        $shipped = trim((string) $order->tracking_number) !== '';

        return AppFulfillment::block('sales', (int) $order->order_id, $shipped ? 'shipped' : 'to_pack', $shipped ? [] : ['mark_shipped']);
    }

    public function pendingCount(Request $request)
    {
        $toPack = \App\Support\FulfillmentBadge::byStore($request->user());

        $pendingStatuses = OrderStatus::where(function ($q) {
            $q->where('name', 'like', '%pending%')
              ->orWhere('name', 'like', '%processing%');
        })->pluck('order_status_id');

        $count = Order::whereIn('order_status_id', $pendingStatuses)->count();

        $lazadaPending = 0;
        if (Schema::hasTable('ch_lazada_orders')) {
            $lazadaPending = DB::table('ch_lazada_orders')
                ->whereIn('status', ['pending', 'repacked'])
                ->count();
        }

        $shopeePending = 0;
        if (Schema::hasTable('ch_shopee_orders')) {
            $shopeePending = DB::table('ch_shopee_orders')
                ->whereIn('status', ['READY_TO_SHIP'])
                ->count();
        }

        $tiktokPending = 0;
        if (Schema::hasTable('ch_tiktok_orders')) {
            $tiktokPending = DB::table('ch_tiktok_orders')
                ->whereIn('status', ['AWAITING_SHIPMENT'])
                ->count();
        }

        $ventaCartPending = [];
        if (Schema::hasTable('ch_ventacart_orders') && Schema::hasTable('ch_ventacart_stores')) {
            $enabledStoreIds = DB::table('ch_ventacart_stores')
                ->where('enabled', true)
                ->pluck('id');

            $pendingCoreIds = $pendingStatuses;

            if ($enabledStoreIds->isNotEmpty()) {
                $pendingVentaStatuses = DB::table('ch_ventacart_order_status_map')
                    ->whereIn('store_id', $enabledStoreIds)
                    ->whereIn('order_status_id', $pendingCoreIds)
                    ->pluck('ventacart_status_name', 'store_id')
                    ->groupBy(fn ($val, $key) => $key);

                foreach ($enabledStoreIds as $storeId) {
                    $mappedStatuses = isset($pendingVentaStatuses[$storeId])
                        ? $pendingVentaStatuses[$storeId]->values()->toArray()
                        : [];

                    if (! empty($mappedStatuses)) {
                        $ventaCartPending[(string) $storeId] = DB::table('ch_ventacart_orders')
                            ->where('store_id', $storeId)
                            ->whereIn('status', $mappedStatuses)
                            ->count();
                    } else {
                        $ventaCartPending[(string) $storeId] = DB::table('ch_ventacart_orders')
                            ->where('store_id', $storeId)
                            ->where(function ($q) {
                                $q->where('status', 'like', '%pending%')
                                    ->orWhere('status', 'like', '%processing%');
                            })
                            ->count();
                    }
                }
            }
        }

        $lazadaByStore = $this->waitingByStore('ch_lazada_orders', 'ch_lazada_stores', 'store_id', ['pending', 'repacked']);
        $shopeeByStore = $this->waitingByStore('ch_shopee_orders', 'ch_shopee_stores', 'store_id', ['READY_TO_SHIP']);
        $tiktokByStore = $this->waitingByStore('ch_tiktok_orders', 'ch_tiktok_stores', 'store_id', ['AWAITING_SHIPMENT']);

        return response()->json([
            'count'          => $count,
            'lazada_pending' => $lazadaPending,
            'shopee_pending' => $shopeePending,
            'tiktok_pending' => $tiktokPending,
            'ventacart_pending'  => (object) $ventaCartPending,
            'lazada_pending_by_store' => $lazadaByStore,
            'shopee_pending_by_store' => $shopeeByStore,
            'tiktok_pending_by_store' => $tiktokByStore,
            'pending_by_channel' => [
                'lazada' => $lazadaPending,
                'shopee' => $shopeePending,
                'tiktok' => $tiktokPending,
                'ventacart' => array_sum($ventaCartPending),
            ],
            'pending_by_store' => [
                'lazada' => $lazadaByStore,
                'shopee' => $shopeeByStore,
                'tiktok' => $tiktokByStore,
                'ventacart' => (object) $ventaCartPending,
            ],
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

    private static function resolveSourceLabel(string $source, array $openCartStoreNames): string
    {
        if (preg_match('/^opencart:(\d+)$/', $source, $m)) {
            return $openCartStoreNames[(int) $m[1]] ?? 'OpenCart';
        }

        return $source;
    }

    private function resolveProductImages($orderProducts): array
    {
        $result = [];
        foreach ($orderProducts as $op) {
            $url = LineImage::url($op->sku, null, (int) $op->product_id ?: null);
            if ($url !== null) {
                $result[$op->order_product_id] = $url;
            }
        }

        return $result;
    }
}
