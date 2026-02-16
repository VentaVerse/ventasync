<?php

namespace App\Http\Controllers;

use App\Models\LazadaSetting;
use App\Models\LazadaApiLog;
use App\Models\LazadaOrder;
use App\Models\LazadaOrderProduct;
use App\Services\Lazada\LazadaClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class LazadaOrderController extends Controller
{
    public function index(Request $request)
    {
        $setting = LazadaSetting::query()->first();

        $filters = $request->validate([
            'order_number' => ['nullable', 'string', 'max:80'],
            'buyer_name' => ['nullable', 'string', 'max:120'],
        ]);

        $orderNumber = trim((string)($filters['order_number'] ?? ''));
        $buyerName = trim((string)($filters['buyer_name'] ?? ''));

        $perPage = (int)$request->query('per_page', 10);
        if (!in_array($perPage, [10,20,50], true)) { $perPage = 10; }

        $tab = strtoupper((string)$request->query('tab', 'ALL'));

        // Lazada "PENDING" has internal workflow steps (to pack -> to arrange shipment -> to handover).
        // We keep the main tabs stable, and add an optional secondary filter only when user is in PENDING.
        $pendingSubtab = (string) $request->query('pending_sub', $tab === 'PENDING' ? 'to_pack' : '');
        if ($tab !== 'PENDING') {
            $pendingSubtab = '';
        }

        $allowedTabs = [
            'ALL',
            'UNPAID',
            'PENDING',
            'SHIPPING',
            'DELIVERED_COMPLETED',
            'CANCELLED',
            'RETURN_FAILED_DELIVERY',
            'RETURN_CUSTOMER',
        ];
        if (!in_array($tab, $allowedTabs, true)) { $tab = 'ALL'; }

        // Basic status mapping (DB status values). Can be refined per platform as we standardize statuses.
        $tabStatusMap = [
            'UNPAID' => ['unpaid'],            'PENDING' => ['pending','packed','ready_to_ship'],
            'SHIPPING' => ['shipped'],
            'DELIVERED_COMPLETED' => ['delivered'],
            'CANCELLED' => ['canceled', 'cancelled'],
            // Returns may need more granular mapping later; leave as broad until we standardize.
            'RETURN_FAILED_DELIVERY' => ['returned'],
            'RETURN_CUSTOMER' => ['returned'],
        ];

        $tabs = [
            'ALL' => 'ALL',
            'UNPAID' => 'UNPAID',
            'PENDING' => 'PENDING',
            'SHIPPING' => 'SHIPPING',
            'DELIVERED_COMPLETED' => 'DELIVERED / COMPLETED',
            'CANCELLED' => 'CANCELLED',
            'RETURN_FAILED_DELIVERY' => 'RETURN DUE TO FAILED DELIVERY',
            'RETURN_CUSTOMER' => 'RETURN DUE TO CUSTOMER',
        ];

        $query = LazadaOrder::query()
            ->where('region', $setting->region ?? 'ph')
            ->with('products');

        // Base query used for badge counts (DB only)
        $baseCountQuery = LazadaOrder::query()->where('region', $setting->region ?? 'ph');


        if ($tab !== 'ALL') {
            // Lazada "PENDING" has internal workflow steps (to pack -> to arrange -> to handover).
            // We keep the main tabs stable, and add an optional secondary filter only when user is in PENDING.
            if ($tab === 'PENDING' && $pendingSubtab !== '') {
                $pendingMap = [
                    'to_pack' => ['pending'],
                    'to_arrange' => ['packed'],
                    'to_handover' => ['ready_to_ship'],
                ];
                $statuses = $pendingMap[$pendingSubtab] ?? ['pending'];
                $query->whereIn('status', $statuses);
            } else {
                $statuses = $tabStatusMap[$tab] ?? [];
                if (!empty($statuses)) {
                    $query->whereIn('status', $statuses);
                }
            }
        }


        // Counts for PENDING sub-tabs (DB only)
        $pending_sub_counts = [
            'to_pack' => (clone $baseCountQuery)->whereIn('status', ['pending'])->count(),
            'to_arrange' => (clone $baseCountQuery)->whereIn('status', ['packed'])->count(),
            'to_handover' => (clone $baseCountQuery)->whereIn('status', ['ready_to_ship'])->count(),
        ];
        // Search filters (safe, parameter-bound). LazadaOrder currently stores customer info in raw JSON.
        if ($orderNumber !== '') {
            $query->where('order_id', 'like', '%' . $orderNumber . '%');
        }

        if ($buyerName !== '') {
            $query->where(function ($q) use ($buyerName) {
                $like = '%' . $buyerName . '%';
                $q->where('raw->customer_first_name', 'like', $like)
                    ->orWhere('raw->customer_name', 'like', $like)
                    ->orWhere('raw->buyer_name', 'like', $like);
            });
        }

        // Counts for tab badges (DB only; UI will still show live status per row).
        $tab_counts = [];
        $tab_counts['ALL'] = (clone $baseCountQuery)->count();
        foreach ($tabStatusMap as $k => $statuses) {
            if (!empty($statuses)) {
                $tab_counts[$k] = (clone $baseCountQuery)->whereIn('status', $statuses)->count();
            } else {
                $tab_counts[$k] = 0;
            }
        }

        $orders = $query
            ->orderByDesc('order_created_at')
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->appends($request->query());

        // IMPORTANT: Do NOT call Lazada APIs from the listing page.
        // Doing so quickly hits SellerCallLimit and prevents products from syncing reliably.
        // Products (and any status refresh) should happen during explicit user actions (Fetch Orders / Pack / RTS).
        $live_statuses = [];

        return view('lazada.orders.index', [
            'setting' => $setting,
            'orders' => $orders,
            'per_page' => $perPage,
            'last_result' => session('lazada_orders_last_result'),
            'tabs' => $tabs,
            'active_tab' => $tab,
            'pending_subtab' => $pendingSubtab,
            'tab_counts' => $tab_counts,
            'pending_sub_counts' => $pending_sub_counts,
            'live_statuses' => $live_statuses,
            'filters' => [
                'order_number' => $orderNumber,
                'buyer_name' => $buyerName,
            ],
        ]);
}

    /**
     * Order detail page.
     * Best-effort: loads from DB first, then optionally refreshes from Lazada APIs.
     */
    public function show(Request $request, LazadaClient $client, string $orderId)
    {
        $orderId = trim($orderId);
        if ($orderId === '' || mb_strlen($orderId) > 80) {
            abort(404);
        }

        $settingRaw = LazadaSetting::query()->first();
        $region = $settingRaw->region ?? 'ph';

        $order = LazadaOrder::query()
            ->where('region', $region)
            ->where('order_id', $orderId)
            ->with('products')
            ->firstOrFail();

        $apiOrder = null;
        $apiItemsSynced = false;
        $apiError = null;

        // Refresh is explicit to avoid hitting SellerCallLimit accidentally.
        // If we detect the stored payload is missing common detail nodes, we refresh once (cached).
        $refresh = (bool) $request->boolean('refresh');
        $needsDetail = $refresh || !$this->rawLikelyHasOrderDetail($order->raw ?? []);

        $setting = $this->decryptSetting($settingRaw);
        $hasCreds = $setting && $setting->region && $setting->app_key && $setting->app_secret && $setting->access_token;

        if ($hasCreds && $needsDetail) {
            try {
                $cacheKey = 'lazada.order.detail.' . $setting->region . '.' . $orderId;
                $ttlSeconds = 300;
                if ($refresh) {
                    Cache::forget($cacheKey);
                }

                $apiOrder = Cache::remember($cacheKey, $ttlSeconds, function () use ($client, $setting, $orderId) {
                    $res = $this->runSignedApiCall(
                        $client,
                        $setting->region,
                        $setting->app_key,
                        $setting->app_secret,
                        $setting->access_token,
                        'GET',
                        '/order/get',
                        true,
                        ['order_id' => $orderId],
                        'lazada.order.get'
                    );

                    if (!($res['ok'] ?? false)) {
                        return ['_ok' => false, '_raw' => $res];
                    }

                    $body = $res['body'] ?? [];
                    $dataNode = is_array($body) ? ($body['data'] ?? $body) : [];
                    $ord = $dataNode['order'] ?? $dataNode;
                    if (!is_array($ord)) {
                        $ord = [];
                    }

                    return ['_ok' => true, '_raw' => $res, 'order' => $ord];
                });

                if (is_array($apiOrder) && ($apiOrder['_ok'] ?? false) === true && isset($apiOrder['order']) && is_array($apiOrder['order'])) {
                    // Persist the detailed payload while keeping the original list payload.
                    $raw = is_array($order->raw) ? $order->raw : [];
                    $raw['_detail'] = $apiOrder['order'];
                    $order->raw = $raw;
                    $order->save();
                } else {
                    $apiError = is_array($apiOrder) ? ($apiOrder['_raw'] ?? $apiOrder) : $apiOrder;
                }
            } catch (\Throwable $e) {
                $apiError = ['exception' => $e->getMessage()];
            }
        }

        // Sync items on demand only (or when missing).
        if ($hasCreds && ($refresh || !$order->products()->limit(1)->exists())) {
            try {
                $synced = $this->syncOrderProductsFromApi($client, $setting, $order);
                if ($synced !== null) {
                    $apiItemsSynced = true;
                    $order->load('products');
                }
            } catch (\Throwable $e) {
                // Best-effort; ignore.
            }
        }

        $raw = is_array($order->raw) ? $order->raw : [];
        $detail = (isset($raw['_detail']) && is_array($raw['_detail'])) ? $raw['_detail'] : [];

        return view('lazada.orders.show', [
            'order' => $order,
            'setting_region' => $region,
            'detail' => $detail,
            'api_items_synced' => $apiItemsSynced,
            'api_error' => $apiError,
        ]);
    }

    /**
     * Heuristic: the /orders/get payload is usually missing address/receiver fields.
     * If we don't see any of these nodes, we likely need /order/get.
     */
    private function rawLikelyHasOrderDetail(array $raw): bool
    {
        if (isset($raw['_detail']) && is_array($raw['_detail']) && count($raw['_detail']) > 0) {
            return true;
        }

        foreach (['address_shipping', 'shipping_address', 'address', 'receiver_name', 'customer_address'] as $k) {
            if (array_key_exists($k, $raw)) {
                return true;
            }
        }

        // Some ventures return these at root in /order/get.
        foreach (['items_count', 'voucher', 'payment_method', 'invoice_number'] as $k) {
            if (array_key_exists($k, $raw)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lazada "Pack & Print" flow entrypoint.
     * Packs the order immediately (creates packages) then redirects back and opens a modal.
     */
    public function packAndPrint(Request $request, LazadaClient $client, $orderId)
    {
        $setting = $this->decryptSetting(LazadaSetting::query()->first());
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret || !$setting->access_token) {
            return redirect()->back()->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'Missing Lazada credentials/token. Please configure Lazada settings and generate access token first.',
            ]);
        }

        $items = $this->getOrderItems($client, $setting, $orderId);
        if (!$items['ok']) {
            return redirect()->back()->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'Failed to fetch order items before packing.',
                'raw' => $items['raw'],
            ]);
        }

        if ($items['is_sof']) {
            return redirect()->back()->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'This order appears to be an SOF/DBS order and does not support Pack/Print AWB via these APIs.',
                'raw' => $items['raw'],
            ]);
        }

        if (count($items['order_item_ids']) === 0) {
            return redirect()->back()->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'No order_item_ids found for this order.',
                'raw' => $items['raw'],
            ]);
        }

        // IMPORTANT:
        // Many Lazada tenants require the payload to be wrapped in a single parameter called "packReq",
        // and it must be a JSON string (application/x-www-form-urlencoded).
        // Lazada param mapping can also be strict: order_item_list should be a plain list of IDs (often as strings).
        $orderItemList = [];
        foreach (array_values($items['order_item_ids']) as $oid) {
            $orderItemList[] = (string) $oid;
        }

        $packReqPayload = [
            'delivery_type' => 'dropship',
            'shipping_allocate_type' => 'TFS',
            'pack_order_list' => [
                [
                    'order_id' => (string) $orderId,
                    'order_item_list' => $orderItemList,
                ],
            ],
        ];

        $packRes = $this->runSignedApiCall(
            $client,
            $setting->region,
            $setting->app_key,
            $setting->app_secret,
            $setting->access_token,
            'POST',
            '/order/fulfill/pack',
            true,
            [
                // Force packReq to be a JSON string. runSignedApiCall will NOT re-encode scalars.
                'packReq' => json_encode($packReqPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ],
            'lazada.order.fulfill.pack'
        );

        if (!($packRes['ok'] ?? false)) {
            return redirect()->back()->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'Failed to pack order items.',
                'raw' => $packRes,
            ]);
        }

        $body = $packRes['body'] ?? [];
        if (is_array($body) && isset($body['code']) && isset($body['message'])) {
            return redirect()->back()->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'Lazada error: ' . $body['code'] . ' - ' . $body['message'],
                'raw' => $packRes,
            ]);
        }

        // Best-effort: refresh local order status
        try {
            $st = $this->fetchOrderStatusFromApi($client, $setting, (string)$orderId);
            if ($st !== null) {
                LazadaOrder::query()->where('region', $setting->region)->where('order_id', (string)$orderId)->update(['status' => $st]);
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return redirect()->back()->with('lazada_orders_last_result', [
            'ok' => true,
            'message' => 'Packed. Choose Print Only, Ship & Print, or Recreate Package.',
            'raw' => $packRes,
        ])->with('open_pack_print_modal_order_id', (string)$orderId);
    }

    /**
     * Packing List (printable HTML) for a single order.
     * Uses DB products when available, otherwise falls back to Lazada /order/items/get.
     */
    public function packingList(Request $request, LazadaClient $client, $orderId)
    {
        $setting = $this->decryptSetting(LazadaSetting::query()->first());
        $order = LazadaOrder::query()
            ->where('order_id', (string)$orderId)
            ->with('products')
            ->first();

        $items = $this->buildPrintableItems($client, $setting, $orderId, $order);
        if (!$items['ok']) {
            return redirect()->back()->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'Failed to build packing list items.',
                'raw' => $items['raw'] ?? null,
            ]);
        }

        return view('lazada.orders.packing_list', [
            'order' => $order,
            'orderId' => (string)$orderId,
            'items' => $items['items'],
        ]);
    }

    /**
     * Pick List (printable HTML) for a single order.
     * Uses DB products when available, otherwise falls back to Lazada /order/items/get.
     */
    public function pickList(Request $request, LazadaClient $client, $orderId)
    {
        $setting = $this->decryptSetting(LazadaSetting::query()->first());
        $order = LazadaOrder::query()
            ->where('order_id', (string)$orderId)
            ->with('products')
            ->first();

        $items = $this->buildPrintableItems($client, $setting, $orderId, $order);
        if (!$items['ok']) {
            return redirect()->back()->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'Failed to build pick list items.',
                'raw' => $items['raw'] ?? null,
            ]);
        }

        // For pick list, a simple stable sort helps pickers (SKU then name)
        $sorted = $items['items'];
        usort($sorted, function ($a, $b) {
            $as = strtolower((string)($a['sku'] ?? ''));
            $bs = strtolower((string)($b['sku'] ?? ''));
            if ($as === $bs) {
                return strcmp(strtolower((string)($a['name'] ?? '')), strtolower((string)($b['name'] ?? '')));
            }
            return strcmp($as, $bs);
        });

        return view('lazada.orders.pick_list', [
            'order' => $order,
            'orderId' => (string)$orderId,
            'items' => $sorted,
        ]);
    }

    /**
     * Logistics status / trace (JSON) from Lazada.
     * Lazada documents this as /logistic/order/trace, available after ready_to_ship.
     */
    public function logisticsTrace(Request $request, LazadaClient $client, $orderId)
    {
        $setting = $this->decryptSetting(LazadaSetting::query()->first());
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret || !$setting->access_token) {
            return response()->json([
                'ok' => false,
                'message' => 'Missing Lazada credentials/token.',
            ], 422);
        }

        $sellerId = $this->getSellerId($client, $setting);
        if ($sellerId === '') {
            return response()->json([
                'ok' => false,
                'message' => 'Unable to resolve seller_id for this token.',
            ], 422);
        }

        $locale = (string)($request->query('locale', 'en_US'));
        if (!preg_match('/^[a-z]{2}_[A-Z]{2}$/', $locale)) {
            $locale = 'en_US';
        }

        $res = $this->runSignedApiCall(
            $client,
            $setting->region,
            $setting->app_key,
            $setting->app_secret,
            $setting->access_token,
            'GET',
            '/logistic/order/trace',
            true,
            [
                'seller_id' => (string)$sellerId,
                'order_id' => (string)$orderId,
                'locale' => $locale,
            ],
            'lazada.logistic.order.trace'
        );

        $ok = (bool)($res['ok'] ?? false);
        $body = $res['body'] ?? [];

        return response()->json([
            'ok' => $ok,
            'body' => $body,
        ], $ok ? 200 : 502);
    }

    /**
     * Ship & Print:
     * - Sets RTS (moves to handover)
     * - Redirects to AWB PDF.
     * Use target="_blank" in the UI so the PDF opens in a new tab.
     */
    public function shipAndPrint(Request $request, LazadaClient $client, $orderId)
    {
        $res = $this->shipAndPrintInternal($request, $client, $orderId, true);
        if (!($res['ok'] ?? false)) {
            return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => $res['message'] ?? 'Failed to ship & print.',
                'raw' => $res['raw'] ?? null,
            ]);
        }

        // Direct open (same tab) for legacy usage: redirect to AWB
        return redirect()->route('lazada.orders.awb', ['orderId' => $orderId]);
    }

    /**
     * Ship (RTS) and then open AWB in a NEW TAB (AWB only), while keeping the user on the current orders tab.
     */
    public function shipAndPrintPost(Request $request, LazadaClient $client, $orderId)
    {
        $res = $this->shipAndPrintInternal($request, $client, $orderId, true);
        if (!($res['ok'] ?? false)) {
            return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => $res['message'] ?? 'Failed to ship & print.',
                'raw' => $res['raw'] ?? null,
            ]);
        }

        // Store the AWB URL in session so the orders page can open it in a new tab.
        return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
            'ok' => true,
            'message' => 'Order set to Ready To Ship. Opening AWB…',
        ])->with('lazada_awb_url', route('lazada.orders.awb', ['orderId' => $orderId]));
    }

    /**
     * Shared logic for ship & print.
     * If $returnArray is true, returns ['ok'=>bool,'message'=>string,'raw'=>mixed].
     */
    private function shipAndPrintInternal(Request $request, LazadaClient $client, $orderId, bool $returnArray = false)
    {
        $setting = $this->decryptSetting(LazadaSetting::query()->first());
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret || !$setting->access_token) {
            return ['ok' => false, 'message' => 'Missing Lazada credentials/token. Please configure Lazada settings and generate access token first.'];
        }

        $order = LazadaOrder::query()->where('region', $setting->region)->where('order_id', (string)$orderId)->first();
        if (!$order) {
            return ['ok' => false, 'message' => 'Order not found in local database. Please fetch orders first.'];
        }

        $packageIds = [];
        try {
            $raw = $order->raw ? json_decode($order->raw, true) : [];
            $p = $raw['package_id'] ?? ($raw['package_ids'] ?? null);
            if (is_string($p) && $p !== '') {
                $packageIds = [$p];
            } elseif (is_array($p)) {
                $packageIds = $p;
            }
        } catch (\Throwable $e) {
            $packageIds = [];
        }

        if (empty($packageIds)) {
            $items = $this->getOrderItems($client, $setting, $orderId);
            $packageIds = $items['package_ids'] ?? [];
        }

        if (empty($packageIds)) {
            return ['ok' => false, 'message' => 'No package_id found for this order. Please Pack first.'];
        }

        $packages = array_map(function ($pid) {
            return ['package_id' => (string)$pid];
        }, $packageIds);

        // Lazada's /order/package/rts expects a mandatory "readyToShipReq" parameter.
        // IMPORTANT: Do not send empty params (e.g. tracking_code/shipment_provider) or signature validation may fail.
        // It must be a JSON string payload.
        $readyToShipReq = json_encode([
            'delivery_type' => 'dropship',
            'packages' => $packages,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $rtsRes = $this->runSignedApiCall(
            $client,
            $setting->region,
            $setting->app_key,
            $setting->app_secret,
            $setting->access_token,
            'POST',
            '/order/package/rts',
            true,
            [
                'readyToShipReq' => $readyToShipReq,
            ],
            'lazada.order.package.rts'
        );

        if (!($rtsRes['ok'] ?? false)) {
            return ['ok' => false, 'message' => 'Failed to set Ready To Ship.', 'raw' => $rtsRes];
        }

        $body = $rtsRes['body'] ?? [];
        if (is_array($body) && isset($body['code']) && isset($body['message'])) {
            return ['ok' => false, 'message' => 'Lazada error: ' . $body['code'] . ' - ' . $body['message'], 'raw' => $rtsRes];
        }

        try {
            $st = $this->fetchOrderStatusFromApi($client, $setting, (string)$orderId);
            if ($st !== null) {
                LazadaOrder::query()->where('region', $setting->region)->where('order_id', (string)$orderId)->update(['status' => $st]);
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return ['ok' => true];
    }

/**
     * Cancel reasons (AJAX).
     */
    public function cancelReasons(Request $request, LazadaClient $client, $orderId)
    {
        $setting = $this->decryptSetting(LazadaSetting::query()->first());
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret || !$setting->access_token) {
            return response()->json(['ok' => false, 'message' => 'Missing Lazada credentials/token.'], 400);
        }

        $res = $this->runSignedApiCall(
            $client,
            $setting->region,
            $setting->app_key,
            $setting->app_secret,
            $setting->access_token,
            'GET',
            '/order/failure_reason/get',
            false,
            [],
            'lazada.order.failure_reason.get'
        );

        if (!($res['ok'] ?? false)) {
            return response()->json(['ok' => false, 'message' => 'Failed to fetch failure reasons.'], 500);
        }

        $body = $res['body'] ?? [];
        if (is_array($body) && isset($body['code']) && isset($body['message'])) {
            return response()->json(['ok' => false, 'message' => 'Lazada error: ' . $body['code'] . ' - ' . $body['message']], 400);
        }

        $data = is_array($body) ? ($body['data'] ?? $body) : $body;
        return response()->json(['ok' => true, 'data' => $data]);
    }

    /**
     * Cancel order (cancels every order item in the order).
     */
    public function cancel(Request $request, LazadaClient $client, $orderId)
    {
        $request->validate([
            'reason_id' => ['required', 'integer'],
            'reason_detail' => ['nullable', 'string', 'max:500'],
        ]);

        $setting = $this->decryptSetting(LazadaSetting::query()->first());
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret || !$setting->access_token) {
            return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'Missing Lazada credentials/token. Please configure Lazada settings and generate access token first.',
            ]);
        }

        $items = $this->getOrderItems($client, $setting, $orderId);
        if (!$items['ok']) {
            return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'Failed to fetch order items before cancellation.',
                'raw' => $items['raw'],
            ]);
        }

        if (count($items['order_item_ids']) === 0) {
            return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'No order items found to cancel.',
            ]);
        }

        $reasonId = (int)$request->input('reason_id');
        $reasonDetail = (string)($request->input('reason_detail') ?? '');

        $errors = [];
        foreach ($items['order_item_ids'] as $oid) {
            $cancelRes = null;
            $attempt = 0;
            $maxAttempts = 5;

            // Throttle calls to avoid hitting Lazada rate limits (ApiCallLimit).
            // Keep a minimum interval between calls during this request.
            static $lastCancelCallAtUs = 0;

            while (true) {
                $nowUs = (int)(microtime(true) * 1000000);
                $minIntervalUs = 1100000; // 1.1s between cancel calls
                if ($lastCancelCallAtUs > 0 && ($nowUs - $lastCancelCallAtUs) < $minIntervalUs) {
                    usleep($minIntervalUs - ($nowUs - $lastCancelCallAtUs));
                }

                $cancelRes = $this->runSignedApiCall(
                    $client,
                    $setting->region,
                    $setting->app_key,
                    $setting->app_secret,
                    $setting->access_token,
                    'POST',
                    '/order/cancel',
                    true,
                    [
                        'reason_id' => $reasonId,
                        'reason_detail' => $reasonDetail,
                        'order_item_id' => $oid,
                    ],
                    'lazada.order.cancel'
                );

                $lastCancelCallAtUs = (int)(microtime(true) * 1000000);

                $bodyTmp = $cancelRes['body'] ?? [];
                $codeTmp = is_array($bodyTmp) ? ($bodyTmp['code'] ?? null) : null;

                // Lazada rate limiting: respect the ban window and retry a few times.
                if ($codeTmp === 'ApiCallLimit' && $attempt < $maxAttempts) {
                    $attempt++;

                    $msg = is_array($bodyTmp) ? (string)($bodyTmp['message'] ?? '') : '';
                    $banSeconds = 1;
                    if (preg_match('/\bban\s+will\s+last\s+(\d+)\s+seconds?\b/i', $msg, $mm)) {
                        $banSeconds = max(1, (int)$mm[1]);
                    }

                    // Sleep for ban duration (+1s safety) then retry.
                    sleep($banSeconds + 1);
                    continue;
                }

                break;
            }

            $body = $cancelRes['body'] ?? [];
            if (!($cancelRes['ok'] ?? false) || (is_array($body) && isset($body['code']) && isset($body['message']))) {
                $errors[] = [
                    'order_item_id' => $oid,
                    'raw' => $cancelRes,
                ];
            }
        }

        if (!empty($errors)) {
            return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'Some items failed to cancel. Please try again or check Lazada Seller Center.',
                'raw' => $errors,
            ]);
        }

        try {
            $st = $this->fetchOrderStatusFromApi($client, $setting, (string)$orderId);
            if ($st !== null) {
                LazadaOrder::query()->where('region', $setting->region)->where('order_id', (string)$orderId)->update(['status' => $st]);
            } else {
                LazadaOrder::query()->where('region', $setting->region)->where('order_id', (string)$orderId)->update(['status' => 'canceled']);
            }
        } catch (\Throwable $e) {
            LazadaOrder::query()->where('region', $setting->region)->where('order_id', (string)$orderId)->update(['status' => 'canceled']);
        }

        return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
            'ok' => true,
            'message' => 'Order cancellation submitted to Lazada.',
        ]);
    }

    /**
     * "Recreate Package" (local-only fallback).
     * For now we revert the local status so the order goes back to "To Pack" in our ERP.
     */
    public function recreatePackage(Request $request, $orderId)
    {
        $setting = LazadaSetting::query()->first();
        LazadaOrder::query()->where('region', $setting->region ?? 'ph')->where('order_id', (string)$orderId)->update(['status' => 'pending']);
        return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
            'ok' => true,
            'message' => 'Order moved back to To Pack (local). If Lazada does not reflect it, use Seller Center to repack/recreate.',
        ]);
    }


    public function fetch(Request $request, LazadaClient $client)
    {
        // Long-running network operations (best-effort; may be ignored by host settings)
        try { @set_time_limit(0); } catch (\Throwable $e) {}

        $data = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'status' => 'nullable|string|max:64',
        ]);

        // Require both dates when using manual range
        $hasFrom = !empty($data['date_from']);
        $hasTo = !empty($data['date_to']);
        if (($hasFrom && !$hasTo) || (!$hasFrom && $hasTo)) {
            return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'Please provide both From and To dates, or leave both empty for automatic sync.',
            ]);
        }

        // LazadaController::decryptSetting is private; decrypt here to keep controllers decoupled.
        $setting = $this->decryptSetting(LazadaSetting::query()->first());
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret || !$setting->access_token) {
            return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'Missing Lazada credentials/token. Please configure Lazada settings and generate access token first.',
            ]);
        }

        $tz = new \DateTimeZone('Asia/Manila');

// Manual range: use created_after/created_before.
// Auto sync (no dates): use updated_after based on the newest order we have, with a small buffer.
// Lazada GetOrders is paginated and Limit has a hard maximum of 100.
$pageLimit = 100;
// Safety guard to avoid PHP/HTTP timeouts on very large volumes.
// If this cap is reached, narrow the date range and re-run fetch.
$maxOrdersPerRun = 2000;

$baseParams = [
    'limit' => $pageLimit,
];

if ($hasFrom && $hasTo) {
    // Safety: restrict manual fetch to max 14 days to avoid timeouts and API call limits.
	    $fromDt = new \DateTime($data['date_from'], $tz);
	    $toDt = new \DateTime($data['date_to'], $tz);
    if ($toDt < $fromDt) {
        return redirect()->back()->withErrors([
            'date_to' => 'To date must be the same as or after From date.',
        ])->withInput();
    }


    $diffDays = (int)$fromDt->diff($toDt)->days + 1; // inclusive
    if ($diffDays > 14) {
        return redirect()->back()->withErrors([
            'date_to' => 'Maximum fetch range is 14 days. Please select a shorter date range.',
        ])->withInput();
    }

    $createdAfter = (new \DateTime($data['date_from'], $tz))
        ->setTime(0, 0, 0)
        ->format('Y-m-d\\TH:i:sP');
    $createdBefore = (new \DateTime($data['date_to'], $tz))
        ->setTime(23, 59, 59)
        ->format('Y-m-d\\TH:i:sP');

    $baseParams['created_after'] = $createdAfter;
    $baseParams['created_before'] = $createdBefore;
} else {
    // Lazada docs do not publish a strict lookback window; in practice, marketplaces often limit historical fetch.
    // We default initial backfill to 30 days and then move forward using updated_after.
    $historyDays = 14;

    $latestUpdated = LazadaOrder::query()
        ->where('region', $setting->region)
        ->max('order_updated_at');

    if ($latestUpdated) {
        // Incremental sync: pull updates since the newest order we have (with a small buffer).
	        $dt = (new \DateTime($latestUpdated, $tz))->modify('-2 hours');
	        // Safety cap: do not auto-fetch older than 14 days to avoid timeouts / heavy API usage.
	        $minDt = (new \DateTime('now', $tz))->modify('-' . $historyDays . ' days');
	        if ($dt < $minDt) {
	            $dt = $minDt;
	        }
        // Lazada Open Platform commonly uses `update_after` for incremental sync.
        $baseParams['update_after'] = $dt->format('Y-m-d\\TH:i:sP');
    } else {
        // First-time backfill: use created_after because it is supported consistently across ventures.
        $dt = (new \DateTime('now', $tz))->modify('-' . $historyDays . ' days');
        $baseParams['created_after'] = $dt->format('Y-m-d\\TH:i:sP');
    }
}
        if (!empty($data['status'])) {
            $baseParams['status'] = $data['status'];
        }

        // Pull pages until exhausted.
        $orders = [];
        $res = null;
        $hitMaxOrdersCap = false;
        $offset = 0;
        while (true) {
            $params = $baseParams;
            $params['offset'] = $offset;

            $res = $this->runSignedApiCall(
                $client,
                $setting->region,
                $setting->app_key,
                $setting->app_secret,
                $setting->access_token,
                'GET',
                '/orders/get',
                true,
                $params,
                'lazada.orders.get'
            );

            $body = $res['body'] ?? [];
            $pageOrders = [];
            if (($res['ok'] ?? false) && is_array($body)) {
                $dataNode = $body['data'] ?? $body;
                $pageOrders = $dataNode['orders'] ?? $dataNode['order_list'] ?? $dataNode['orders_list'] ?? $dataNode['data'] ?? [];
                if (!is_array($pageOrders)) $pageOrders = [];
            }

            // If the API returned an error, stop paging and bubble the message.
            if (!($res['ok'] ?? false)) {
                break;
            }

            $orders = array_merge($orders, $pageOrders);

            // Safety guard: if there are too many orders in this range, stop and ask user to narrow.
            if (count($orders) >= $maxOrdersPerRun) {
                $hitMaxOrdersCap = true;
                break;
            }

            // stop if fewer than limit returned
            if (count($pageOrders) < $pageLimit) {
                break;
            }

            $offset += $pageLimit;
        }

        $body = $res['body'] ?? [];

        // Persist orders so they remain after refresh
        $saved = 0;
        $updated = 0;

        foreach ($orders as $o) {
            if (!is_array($o)) continue;

            // IMPORTANT: Lazada item APIs (e.g., /order/items/get) expect the numeric `order_id`.
            // Prefer `order_id` and fall back to `order_number` only if needed.
            $orderId = (string)($o['order_id'] ?? $o['orderId'] ?? $o['order_number'] ?? $o['orderNumber'] ?? '');
            if ($orderId === '') continue;

            $statusVal = $o['statuses'] ?? $o['status'] ?? $o['order_status'] ?? null;
            if (is_array($statusVal)) {
                $statusVal = $statusVal[0] ?? null;
            }

            $createdAt = $o['created_at'] ?? $o['createdAt'] ?? $o['created_time'] ?? null;
            $updatedAt = $o['updated_at'] ?? $o['updatedAt'] ?? $o['update_time'] ?? null;

            $payload = [
                'region' => $setting->region,
                'order_id' => $orderId,
                'status' => is_string($statusVal) ? $statusVal : null,
                'order_created_at' => $this->parseApiDatetime($createdAt),
                'order_updated_at' => $this->parseApiDatetime($updatedAt),
                'raw' => $o,
            ];

            $existing = LazadaOrder::query()
                ->where('region', $setting->region)
                ->where('order_id', $orderId)
                ->first();

            if ($existing) {
                $existing->fill($payload)->save();
                $updated++;
                // SAFE MODE (default): sync products ONLY when missing.
                // This drastically reduces /order/items/get calls and avoids SellerCallLimit.
                try {
                    $hasProducts = $existing->products()->limit(1)->exists();
                    if (!$hasProducts) {
                        $this->syncOrderProductsFromApi($client, $setting, $existing);
                    }
                } catch (\Throwable $e) {}
            } else {
                $newOrder = LazadaOrder::query()->create($payload);
                $saved++;
                // Best-effort sync of products for this order.
                try { $this->syncOrderProductsFromApi($client, $setting, $newOrder); } catch (\Throwable $e) {}
            }
        }

        $redirectParams = [];
if ($hasFrom && $hasTo) {
    $redirectParams['date_from'] = $data['date_from'];
    $redirectParams['date_to'] = $data['date_to'];
}
if (!empty($data['status'])) {
    $redirectParams['status'] = $data['status'];
}

        $finalMessage = ($res['ok'] ?? false)
            ? ((($saved + $updated) > 0) ? 'Orders are now up to date.' : 'Orders are already up to date.')
            : ($body['message'] ?? null);

        if ($hitMaxOrdersCap) {
            $finalMessage = 'Fetched the maximum ' . $maxOrdersPerRun . ' orders for this request. Please narrow the date range and fetch again to load the remaining orders.';
        }

return redirect()->route('lazada.orders.index', $redirectParams)
            ->with('lazada_orders_last_result', [
                'ok' => (bool)($res['ok'] ?? false),
                'message' => $finalMessage,
                'saved' => $saved,
                'updated' => $updated,
                'raw' => $res,
            ]);
    }


	/**
	 * Update status/updated_at of existing orders (and add any newly updated orders)
	 * without syncing products. This is intended to be fast and API-light.
	 */
	public function updateStatuses(Request $request, LazadaClient $client)
	{
	    // Long-running network operations (best-effort; may be ignored by host settings)
	    try { @set_time_limit(0); } catch (\Throwable $e) {}

	    // LazadaController::decryptSetting is private; decrypt here to keep controllers decoupled.
	    $setting = $this->decryptSetting(LazadaSetting::query()->first());
	    if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret || !$setting->access_token) {
	        return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
	            'ok' => false,
	            'message' => 'Missing Lazada credentials/token. Please configure Lazada settings and generate access token first.',
	        ]);
	    }

	    $tz = new \DateTimeZone('Asia/Manila');
	    $historyDays = 14;
	    $dt = (new \DateTime('now', $tz))->modify('-' . $historyDays . ' days');

	    $baseParams = [
	        'limit' => 50,
	        // Lazada Open Platform uses `update_after` for incremental changes.
	        'update_after' => $dt->format('Y-m-d\\TH:i:sP'),
	    ];

	    // Pull multiple pages.
	    $orders = [];
	    $res = null;
	    for ($offset = 0; $offset <= 450; $offset += 50) {
	        $params = $baseParams;
	        $params['offset'] = $offset;

	        $res = $this->runSignedApiCall(
	            $client,
	            $setting->region,
	            $setting->app_key,
	            $setting->app_secret,
	            $setting->access_token,
	            'GET',
	            '/orders/get',
	            true,
	            $params,
	            'lazada.orders.get'
	        );

	        $body = $res['body'] ?? [];
	        $pageOrders = [];
	        if (($res['ok'] ?? false) && is_array($body)) {
	            $dataNode = $body['data'] ?? $body;
	            $pageOrders = $dataNode['orders'] ?? $dataNode['order_list'] ?? $dataNode['orders_list'] ?? $dataNode['data'] ?? [];
	            if (!is_array($pageOrders)) $pageOrders = [];
	        }

	        if (!($res['ok'] ?? false)) {
	            break;
	        }

	        $orders = array_merge($orders, $pageOrders);
	        if (count($pageOrders) < 50) {
	            break;
	        }
	    }

	    if (!($res['ok'] ?? false)) {
	        $msg = 'Failed to update orders.';
	        $body = $res['body'] ?? [];
	        if (is_array($body)) {
	            $msg = (string)($body['message'] ?? $body['msg'] ?? $msg);
	        }
	        return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
	            'ok' => false,
	            'message' => $msg,
	        ]);
	    }

	    $created = 0;
	    $updated = 0;
	    $skipped = 0;
	    foreach ($orders as $o) {
	        if (!is_array($o)) continue;
	
	        $orderId = (string)($o['order_id'] ?? $o['orderId'] ?? $o['order_number'] ?? $o['orderNumber'] ?? '');
	        if ($orderId === '') continue;
	
	        $statusVal = $o['statuses'] ?? $o['status'] ?? $o['order_status'] ?? null;
	        if (is_array($statusVal)) {
	            $statusVal = $statusVal[0] ?? null;
	        }
	
	        $createdAt = $o['created_at'] ?? $o['createdAt'] ?? $o['created_time'] ?? null;
	        $updatedAt = $o['updated_at'] ?? $o['updatedAt'] ?? $o['update_time'] ?? null;
	
	        $payload = [
	            'region' => $setting->region,
	            'order_id' => $orderId,
	            'status' => is_string($statusVal) ? $statusVal : null,
	            'order_created_at' => $this->parseApiDatetime($createdAt),
	            'order_updated_at' => $this->parseApiDatetime($updatedAt),
	            'raw' => $o,
	        ];
	
	        $existing = LazadaOrder::query()
	            ->where('region', $setting->region)
	            ->where('order_id', $orderId)
	            ->first();
	
	        if ($existing) {
                $existing->fill($payload)->save();
                $updated++;
            } else {
                // IMPORTANT: Update Orders is meant to update statuses of already-synced orders only.
                // Creating header-only orders (without products) causes "empty" orders in the UI and breaks workflows.
                // Newly discovered orders should be added only via "Fetch Orders" (full sync).
                $skipped++;
            }
	    }

	    $message = ($updated === 0 && $skipped === 0)
            ? 'Orders are already up to date.'
            : 'Orders updated. Updated ' . $updated . ($skipped ? (', skipped ' . $skipped . ' not-yet-synced order(s). Use Fetch Orders to add them.') : '.') ;

	    return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
	        'ok' => true,
	        'message' => $message,
	    ]);
	}


/**
 * Reset Lazada orders for the current region (deletes orders and cascades products).
 * This is a manual admin action to allow a clean re-sync.
 */
public function reset(Request $request)
{
    $request->validate([
        'confirm' => 'required|in:RESET',
    ]);

    $setting = $this->decryptSetting(LazadaSetting::query()->first());
    if (!$setting || !$setting->region) {
        return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
            'ok' => false,
            'message' => 'Missing Lazada region setting.',
        ]);
    }

    try {
        \DB::transaction(function () use ($setting) {
            // Delete orders by region; products are deleted via FK cascade.
            LazadaOrder::query()->where('region', $setting->region)->delete();
        });

        return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
            'ok' => true,
            'message' => 'Lazada orders have been reset. Please click Fetch Orders to re-sync.',
        ]);
    } catch (\Throwable $e) {
        return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
            'ok' => false,
            'message' => 'Failed to reset Lazada orders.',
        ]);
    }
}




    /**
     * Step 1 for fulfillment: Pack order items (creates packages that enable AWB printing).
     */
    public function pack(Request $request, LazadaClient $client, $orderId)
    {
        $setting = $this->decryptSetting(LazadaSetting::query()->first());
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret || !$setting->access_token) {
            return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'Missing Lazada credentials/token. Please configure Lazada settings and generate access token first.',
            ]);
        }

        // Fetch items first so we can pass order_item_ids if needed.
        $items = $this->getOrderItems($client, $setting, $orderId);
        if (!$items['ok']) {
            return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'Failed to fetch order items before packing.',
                'raw' => $items['raw'],
            ]);
        }

        if ($items['is_sof']) {
            return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'This order appears to be an SOF/DBS order and does not support Pack/Print AWB via these APIs.',
                'raw' => $items['raw'],
            ]);
        }

        if (count($items['order_item_ids']) === 0) {
            return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'No order_item_ids found for this order.',
                'raw' => $items['raw'],
            ]);
        }

        // IMPORTANT:
        // Many Lazada tenants require the payload to be wrapped in a single parameter called "packReq",
        // and it must be a JSON string (application/x-www-form-urlencoded).
        // Lazada param mapping can also be strict: order_item_list should be a plain list of IDs (often as strings).
        $orderItemList = [];
        foreach (array_values($items['order_item_ids']) as $oid) {
            $orderItemList[] = (string) $oid;
        }

        $packReqPayload = [
            'delivery_type' => 'dropship',
            'shipping_allocate_type' => 'TFS',
            'pack_order_list' => [
                [
                    'order_id' => (string) $orderId,
                    'order_item_list' => $orderItemList,
                ],
            ],
        ];

        $packRes = $this->runSignedApiCall(
            $client,
            $setting->region,
            $setting->app_key,
            $setting->app_secret,
            $setting->access_token,
            'POST',
            '/order/fulfill/pack',
            true,
            [
                // Force packReq to be a JSON string. runSignedApiCall will NOT re-encode scalars.
                'packReq' => json_encode($packReqPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ],
            'lazada.order.fulfill.pack'
        );

        if (!($packRes['ok'] ?? false)) {
            return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'Failed to pack order items.',
                'raw' => $packRes,
            ]);
        }

        $body = $packRes['body'] ?? [];
        if (is_array($body) && isset($body['code']) && isset($body['message'])) {
            return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'Lazada error: ' . $body['code'] . ' - ' . $body['message'],
                'raw' => $packRes,
            ]);
        }

        return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
            'ok' => true,
            'message' => 'Packed. You can now try Print AWB (and then Ready To Ship).',
            'raw' => $packRes,
        ]);
    }

    /**
     * Step 3 for fulfillment: Ready To Ship (RTS) for package-based fulfillment.
     */
    public function rts(Request $request, LazadaClient $client, $orderId)
    {
        $setting = $this->decryptSetting(LazadaSetting::query()->first());
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret || !$setting->access_token) {
            return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'Missing Lazada credentials/token. Please configure Lazada settings and generate access token first.',
            ]);
        }

        $items = $this->getOrderItems($client, $setting, $orderId);
        if (!$items['ok']) {
            return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'Failed to fetch order items before RTS.',
                'raw' => $items['raw'],
            ]);
        }

        if ($items['is_sof']) {
            return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'Lazada error: 50008 - not support operation for sof order',
                'raw' => $items['raw'],
            ]);
        }

        if (count($items['package_ids']) === 0) {
            return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'No packages found for this order yet. Click Pack first (it creates packages), then Print AWB, then RTS.',
                'raw' => $items['raw'],
            ]);
        }

        $packages = array_map(function ($pid) {
            return ['package_id' => $pid];
        }, $items['package_ids']);

        // Lazada's /order/package/rts expects a mandatory "readyToShipReq" parameter.
        // It must be a JSON string payload.
        $readyToShipReq = json_encode([
            'delivery_type' => 'dropship',
            'packages' => $packages,
        ], JSON_UNESCAPED_SLASHES);

        $rtsRes = $this->runSignedApiCall(
            $client,
            $setting->region,
            $setting->app_key,
            $setting->app_secret,
            $setting->access_token,
            'POST',
            '/order/package/rts',
            true,
            [
                'readyToShipReq' => $readyToShipReq,
            ],
            'lazada.order.package.rts'
        );

        if (!($rtsRes['ok'] ?? false)) {
            return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'Failed to set Ready To Ship.',
                'raw' => $rtsRes,
            ]);
        }

        $body = $rtsRes['body'] ?? [];
        if (is_array($body) && isset($body['code']) && isset($body['message'])) {
            return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'Lazada error: ' . $body['code'] . ' - ' . $body['message'],
                'raw' => $rtsRes,
            ]);
        }

        try {
            $st = $this->fetchOrderStatusFromApi($client, $setting, (string)$orderId);
            if ($st !== null) {
                LazadaOrder::query()->where('region', $setting->region)->where('order_id', (string)$orderId)->update(['status' => $st]);
            }
        } catch (\Throwable $e) {}

        return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
            'ok' => true,
            'message' => 'Marked Ready To Ship.',
            'raw' => $rtsRes,
        ]);
    }

    public function awbPdf(Request $request, LazadaClient $client, $orderId)
    {
        // LazadaController::decryptSetting is private; decrypt here to keep controllers decoupled.
        $setting = $this->decryptSetting(LazadaSetting::query()->first());
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret || !$setting->access_token) {
            return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'Missing Lazada credentials/token. Please configure Lazada settings and generate access token first.',
            ]);
        }

        // Package-based printing: Pack -> PrintAWB (/order/package/document/get)
        // Get order items so we can collect package_ids.
        $items = $this->getOrderItems($client, $setting, $orderId);

        if (!$items['ok']) {
            return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'Failed to fetch order items.',
                'raw' => $items['raw'],
            ]);
        }

        if ($items['is_sof']) {
            return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'Lazada error: 50008 - not support operation for sof order',
                'raw' => $items['raw'],
            ]);
        }

        if (count($items['package_ids']) === 0) {
            return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'Lazada error: 700040 - There are no packages that support printing! Click Pack first to generate packages.',
                'raw' => $items['raw'],
            ]);
        }

        $packages = array_map(function ($pid) {
            return ['package_id' => $pid];
        }, $items['package_ids']);

        // Lazada's /order/package/document/get expects a JSON payload wrapped in the
        // "getDocumentReq" parameter.
        // We keep it minimal: doc_type + packages.
        $getDocumentReq = json_encode([
            'doc_type' => 'PDF',
            'packages' => $packages,
        ], JSON_UNESCAPED_SLASHES);

        $awbRes = $this->runSignedApiCall(
            $client,
            $setting->region,
            $setting->app_key,
            $setting->app_secret,
            $setting->access_token,
            'POST',
            '/order/package/document/get',
            true,
            [
                'getDocumentReq' => $getDocumentReq,
            ],
            'lazada.order.package.document.get'
        );

        if (!($awbRes['ok'] ?? false)) {
            return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'Failed to retrieve AWB PDF.',
                'raw' => $awbRes,
            ]);
        }

        $awbBody = $awbRes['body'] ?? [];

        // If Lazada returned an API error at the top-level, surface it early
        if (is_array($awbBody) && isset($awbBody['code']) && isset($awbBody['message'])) {
            return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'Lazada error: ' . $awbBody['code'] . ' - ' . $awbBody['message'],
                'raw' => $awbRes,
            ]);
        }
        $dataNode = is_array($awbBody) ? ($awbBody['data'] ?? $awbBody) : $awbBody;

        // If Lazada returned an API error, surface it
        if (is_array($dataNode) && isset($dataNode['code']) && isset($dataNode['message'])) {
            $code = (string)$dataNode['code'];
            $msg = (string)$dataNode['message'];

            // Common cases in demo/sandbox or non-eligible orders
            if ($code === '700040') {
                $msg .= ' (This order has no printable package yet. Usually you can only print AWB after the order item is packed/ready-to-ship and has a package.)';
            } elseif ($code === '50008') {
                $msg .= ' (This looks like an SOF/DBS order type that does not support this printing operation via this API.)';
            }

            return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'Lazada error: ' . $code . ' - ' . $msg,
                'raw' => $awbRes,
            ]);
        }

        $doc = $this->findDocument($dataNode);
        if (!$doc) {
            return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'AWB PDF response did not contain a document.',
                'raw' => $awbRes,
            ]);
        }

        // Save-to-server behavior:
        // When the user opens the AWB, we also store it into local storage (best-effort) so the server
        // keeps a copy. This avoids extra UI changes and matches the "automatic into the server" request.
        $safeOrderId = preg_replace('/[^0-9A-Za-z_-]/', '', (string)$orderId);
        $storePath = 'lazada/awb/' . ($safeOrderId !== '' ? $safeOrderId : 'awb') . '.pdf';

        if (($doc['type'] ?? '') === 'url') {
            $url = (string)($doc['value'] ?? '');
            if ($url !== '' && !Storage::disk('local')->exists($storePath) && preg_match('#^https?://#i', $url)) {
                try {
                    $resp = Http::timeout(30)->withHeaders([
                        'User-Agent' => 'LaravelERP/1.0',
                    ])->get($url);

                    if ($resp->ok()) {
                        $bin = $resp->body();
                        if (is_string($bin) && $bin !== '' && (str_starts_with($bin, '%PDF') || str_contains(strtolower((string)$resp->header('content-type')), 'pdf'))) {
                            Storage::disk('local')->put($storePath, $bin);
                        }
                    }
                } catch (\Throwable $e) {
                    // Best-effort only; do not block the user from viewing AWB.
                }
            }

            return redirect()->away($url);
        }

        $bin = base64_decode($doc['value'], true);
        if ($bin === false) {
            return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'Invalid base64 document returned from Lazada.',
                'raw' => $awbRes,
            ]);
        }

        // Lazada sometimes returns a base64-encoded HTML iframe that embeds the PDF URL.
        // If so, extract the src URL and redirect there.
        if (!str_starts_with($bin, '%PDF')) {
            if (preg_match('/<iframe[^>]+src=\"([^\"]+)\"/i', $bin, $m) && isset($m[1]) && str_starts_with($m[1], 'http')) {
                $url = (string)$m[1];
                if ($url !== '' && !Storage::disk('local')->exists($storePath) && preg_match('#^https?://#i', $url)) {
                    try {
                        $resp = Http::timeout(30)->withHeaders([
                            'User-Agent' => 'LaravelERP/1.0',
                        ])->get($url);
                        if ($resp->ok()) {
                            $pdf = $resp->body();
                            if (is_string($pdf) && $pdf !== '' && (str_starts_with($pdf, '%PDF') || str_contains(strtolower((string)$resp->header('content-type')), 'pdf'))) {
                                Storage::disk('local')->put($storePath, $pdf);
                            }
                        }
                    } catch (\Throwable $e) {
                        // Best-effort only.
                    }
                }
                return redirect()->away($m[1]);
            }
        }

        // If it's a PDF binary, persist it locally (best-effort)
        if (str_starts_with($bin, '%PDF') && !Storage::disk('local')->exists($storePath)) {
            try {
                Storage::disk('local')->put($storePath, $bin);
            } catch (\Throwable $e) {
                // Best-effort only.
            }
        }

        return response($bin, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="awb_' . $orderId . '.pdf"',
        ]);
    }

    /**
     * Fetch order status from Lazada (best-effort).
     * Returns the raw Lazada status string (lowercased) or null on failure.
     */
    private function fetchOrderStatusFromApi(LazadaClient $client, $setting, string $orderId): ?string
    {
        $res = $this->runSignedApiCall(
            $client,
            $setting->region,
            $setting->app_key,
            $setting->app_secret,
            $setting->access_token,
            'GET',
            '/order/get',
            true,
            ['order_id' => $orderId],
            'lazada.order.get'
        );

        if (!($res['ok'] ?? false)) {
            return null;
        }

        $body = $res['body'] ?? [];
        $dataNode = is_array($body) ? ($body['data'] ?? $body) : [];
        $order = $dataNode['order'] ?? $dataNode;

        $st = $order['statuses'] ?? $order['status'] ?? $order['order_status'] ?? null;
        if (is_array($st)) {
            $st = $st[0] ?? null;
        }
        if (!is_string($st) || trim($st) === '') {
            return null;
        }

        return strtolower(trim($st));
    }

    /**
     * Fetches order items from Lazada and stores them as LazadaOrderProduct rows.
     * Best-effort: if the API fails, it returns null and does not break the page.
     */
    private function syncOrderProductsFromApi(LazadaClient $client, $setting, LazadaOrder $order): ?array
    {
        $oid = (string)($order->order_id ?? '');
        if ($oid === '') return null;

        $itemsRes = $this->runSignedApiCall(
            $client,
            $setting->region,
            $setting->app_key,
            $setting->app_secret,
            $setting->access_token,
            'GET',
            '/order/items/get',
            true,
            ['order_id' => $oid],
            'lazada.order.items.get'
        );

        if (!($itemsRes['ok'] ?? false)) {
            return null;
        }

        $itemsBody = $itemsRes['body'] ?? [];
        $itemsDataNode = is_array($itemsBody) ? ($itemsBody['data'] ?? $itemsBody) : [];
        // Some ventures return the item list directly under `data` as an array.
        if (is_array($itemsDataNode) && array_is_list($itemsDataNode)) {
            $items = $itemsDataNode;
        } else {
            $items = $itemsDataNode['order_items'] ?? $itemsDataNode['items'] ?? $itemsDataNode['data'] ?? [];
        }
        if (!is_array($items)) $items = [];

        $out = [];

        foreach ($items as $it) {
            if (!is_array($it)) continue;

            $orderItemId = (string)($it['order_item_id'] ?? $it['orderItemId'] ?? $it['id'] ?? '');
            if ($orderItemId === '') continue;

            $sellerSku = (string)($it['seller_sku'] ?? $it['SellerSku'] ?? $it['sellerSku'] ?? $it['sku'] ?? $it['Sku'] ?? '');
            $name = (string)($it['name'] ?? $it['product_name'] ?? $it['item_name'] ?? '');

            // Variation/options can appear under different keys.
            $variation = null;
            foreach (['variation', 'sku_variant', 'variation_sku', 'variation_name', 'variation_detail', 'item_variation'] as $k) {
                if (isset($it[$k]) && is_string($it[$k]) && trim($it[$k]) !== '') {
                    $variation = trim($it[$k]);
                    break;
                }
            }
            if ($variation === null) {
                // Some payloads provide a list of options.
                $opts = $it['sku_attributes'] ?? $it['skuAttributes'] ?? $it['variation_attributes'] ?? null;
                if (is_array($opts) && count($opts) > 0) {
                    $pairs = [];
                    foreach ($opts as $op) {
                        if (!is_array($op)) continue;
                        $n = trim((string)($op['name'] ?? $op['attribute_name'] ?? ''));
                        $v = trim((string)($op['value'] ?? $op['attribute_value'] ?? ''));
                        if ($n !== '' && $v !== '') $pairs[] = $n . ': ' . $v;
                    }
                    if (count($pairs) > 0) $variation = implode(', ', $pairs);
                }
            }

            $qty = (int)($it['quantity'] ?? $it['qty'] ?? $it['item_quantity'] ?? 1);

            $image = (string)($it['product_main_image'] ?? $it['image'] ?? $it['item_image'] ?? $it['sku_image'] ?? '');
            if ($image === '') {
                $imgs = $it['images'] ?? $it['Images'] ?? null;
                if (is_array($imgs) && isset($imgs[0]) && is_string($imgs[0])) {
                    $image = $imgs[0];
                }
            }

            $row = LazadaOrderProduct::query()->updateOrCreate(
                [
                    'lazada_order_id' => $order->id,
                    'order_item_id' => $orderItemId,
                ],
                [
                    'sku' => $sellerSku !== '' ? $sellerSku : null,
                    'name' => $name !== '' ? $name : null,
                    'variation' => $variation,
                    'quantity' => $qty,
                    'image' => $image !== '' ? $image : null,
                    'status' => isset($it['status']) && is_string($it['status']) ? $it['status'] : null,
                    'raw' => $it,
                ]
            );

            $out[] = $row;
        }

        return $out;
    }

    /**
     * Fetch order items and normalize the key details we need for Pack/PrintAWB/RTS.
     */
    private function getOrderItems(LazadaClient $client, $setting, $orderId): array
    {
        $itemsRes = $this->runSignedApiCall(
            $client,
            $setting->region,
            $setting->app_key,
            $setting->app_secret,
            $setting->access_token,
            'GET',
            '/order/items/get',
            true,
            ['order_id' => $orderId],
            'lazada.order.items.get'
        );

        if (!($itemsRes['ok'] ?? false)) {
            return [
                'ok' => false,
                'raw' => $itemsRes,
                'order_item_ids' => [],
                'package_ids' => [],
                'is_sof' => false,
            ];
        }

        $itemsBody = $itemsRes['body'] ?? [];
        $itemsDataNode = is_array($itemsBody) ? ($itemsBody['data'] ?? $itemsBody) : [];
        $items = $itemsDataNode['order_items'] ?? $itemsDataNode['items'] ?? $itemsDataNode ?? [];
        if (!is_array($items)) $items = [];

        $orderItemIds = [];
        $packageIds = [];
        $isSof = false;

        foreach ($items as $it) {
            $id = $it['order_item_id'] ?? $it['order_item_id_str'] ?? $it['orderItemId'] ?? $it['id'] ?? null;
            if ($id !== null && $id !== '') {
                $orderItemIds[] = (string)$id;
            }

            $pid = $it['package_id'] ?? $it['packageId'] ?? null;
            if ($pid !== null && $pid !== '') {
                $packageIds[] = (string)$pid;
            }

            // SOF/DBS detection (field name can vary; check raw flags)
            $sofFlag = $it['delivery_option_sof'] ?? $it['is_sof'] ?? $it['isSof'] ?? null;
            if ((string)$sofFlag === '1' || $sofFlag === true) {
                $isSof = true;
            }
        }

        $orderItemIds = array_values(array_unique($orderItemIds));
        $orderItemIds = array_values(array_filter($orderItemIds, function($v) {
            return preg_match('/^[0-9]+$/', (string)$v);
        }));

        $packageIds = array_values(array_unique($packageIds));

        return [
            'ok' => true,
            'raw' => $itemsRes,
            'order_item_ids' => $orderItemIds,
            'package_ids' => $packageIds,
            'is_sof' => $isSof,
        ];
    }

    /**
     * Build printable items for Packing/Pick list.
     * Prefers DB relation products (fast, no API calls). Falls back to Lazada /order/items/get.
     */
    private function buildPrintableItems(LazadaClient $client, $setting, $orderId, ?LazadaOrder $order = null): array
    {
        $items = [];

        // DB first
        if ($order && $order->relationLoaded('products') && $order->products) {
            foreach ($order->products as $p) {
                $items[] = [
                    'order_item_id' => (string)($p->order_item_id ?? ''),
                    'sku' => (string)($p->sku ?? ''),
                    'name' => (string)($p->name ?? ''),
                    'quantity' => (int)($p->quantity ?? 0),
                ];
            }
        }

        $items = array_values(array_filter($items, function ($r) {
            return ($r['name'] ?? '') !== '' || ($r['sku'] ?? '') !== '' || ($r['order_item_id'] ?? '') !== '';
        }));

        if (count($items) > 0) {
            return ['ok' => true, 'items' => $items];
        }

        // Fallback to Lazada API when DB doesn't have products for this order.
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret || !$setting->access_token) {
            return ['ok' => false, 'items' => [], 'raw' => ['message' => 'Missing Lazada credentials/token for items fallback']];
        }

        $itemsRes = $this->runSignedApiCall(
            $client,
            $setting->region,
            $setting->app_key,
            $setting->app_secret,
            $setting->access_token,
            'GET',
            '/order/items/get',
            true,
            ['order_id' => (string)$orderId],
            'lazada.order.items.get.printable'
        );

        if (!($itemsRes['ok'] ?? false)) {
            return ['ok' => false, 'items' => [], 'raw' => $itemsRes];
        }

        $body = $itemsRes['body'] ?? [];
        $data = is_array($body) ? ($body['data'] ?? $body) : [];
        $rows = $data['order_items'] ?? $data['items'] ?? $data ?? [];
        if (!is_array($rows)) { $rows = []; }

        foreach ($rows as $it) {
            if (!is_array($it)) continue;
            $items[] = [
                'order_item_id' => (string)($it['order_item_id'] ?? $it['order_item_id_str'] ?? ''),
                'sku' => (string)($it['seller_sku'] ?? $it['sku'] ?? $it['SellerSku'] ?? ''),
                'name' => (string)($it['name'] ?? $it['item_name'] ?? $it['product_name'] ?? ''),
                'quantity' => (int)($it['quantity'] ?? $it['qty'] ?? 0),
            ];
        }

        $items = array_values(array_filter($items, function ($r) {
            return ($r['name'] ?? '') !== '' || ($r['sku'] ?? '') !== '' || ($r['order_item_id'] ?? '') !== '';
        }));

        return ['ok' => true, 'items' => $items, 'raw' => $itemsRes];
    }

    /**
     * Lazada logistics trace API expects seller_id. Resolve it via /seller/get and cache.
     */
    private function getSellerId(LazadaClient $client, $setting): string
    {
        $region = (string)($setting->region ?? '');
        $appKey = (string)($setting->app_key ?? '');
        $accessToken = (string)($setting->access_token ?? '');
        if ($region === '' || $appKey === '' || $accessToken === '') {
            return '';
        }

        $cacheKey = 'lazada_seller_id:' . $region . ':' . $appKey . ':' . substr(hash('sha256', $accessToken), 0, 12);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $res = $this->runSignedApiCall(
            $client,
            $region,
            (string)$setting->app_key,
            (string)$setting->app_secret,
            $accessToken,
            'GET',
            '/seller/get',
            true,
            [],
            'lazada.seller.get'
        );

        if (!($res['ok'] ?? false)) {
            return '';
        }

        $body = $res['body'] ?? [];
        $data = is_array($body) ? ($body['data'] ?? $body) : [];
        $sellerId = (string)($data['seller_id'] ?? $data['user_id'] ?? $data['sellerId'] ?? '');
        $sellerId = preg_match('/^[0-9]+$/', $sellerId) ? $sellerId : '';
        if ($sellerId !== '') {
            Cache::put($cacheKey, $sellerId, now()->addDays(7));
        }

        return $sellerId;
    }

    private function findDocument($data)
    {
        // Returns: ['type' => 'base64'|'url', 'value' => string] or null
        if (is_array($data)) {
            // Common keys
            // Lazada package document API commonly returns `pdf_url`.
            foreach (['pdf_url', 'document_url', 'url', 'download_url', 'file_url'] as $k) {
                if (isset($data[$k]) && is_string($data[$k]) && str_starts_with($data[$k], 'http')) {
                    return ['type' => 'url', 'value' => $data[$k]];
                }
            }

            // Lazada may return a base64-encoded HTML iframe under `file` (that points to the PDF URL)
            // or a base64 PDF itself.
            foreach (['file', 'document', 'pdf', 'awb_pdf', 'awbPdf'] as $k) {
                if (!isset($data[$k])) continue;

                // document might be a base64 string
                if (is_string($data[$k]) && strlen($data[$k]) > 50) {
                    return ['type' => 'base64', 'value' => $data[$k]];
                }

                // document might be an object/array
                if (is_array($data[$k])) {
                    $nested = $this->findDocument($data[$k]);
                    if ($nested) return $nested;
                }
            }

            // Search recursively
            foreach ($data as $v) {
                $found = $this->findDocument($v);
                if ($found) return $found;
            }
        }

        return null;
    }

    private function runSignedApiCall(
            LazadaClient $client,
            string $region,
            string $appKey,
            string $appSecret,
            string $accessToken,
            string $method,
            string $apiPath,
            bool $authRequired,
            array $customParams,
            ?string $pack = null
        ): array {
            $apiPath = trim($apiPath);
            if ($apiPath === '') {
                return ['status' => 0, 'ok' => false, 'body' => ['message' => 'Missing api_path']];
            }
            if (!str_starts_with($apiPath, '/')) {
                $apiPath = '/' . $apiPath;
            }
    
            if ($authRequired && $accessToken === '') {
                return ['status' => 0, 'ok' => false, 'body' => ['message' => 'Missing access_token for auth-required call']];
            }
    
            $timestamp = (string)round(microtime(true) * 1000);
            $params = [
                'app_key' => $appKey,
                'sign_method' => 'sha256',
                'timestamp' => $timestamp,
            ];
            if ($authRequired) {
                $params['access_token'] = $accessToken;
            }
    
            foreach ($customParams as $k => $v) {
                if (!is_string($k) || $k === '' || in_array($k, ['sign', 'app_key', 'sign_method', 'timestamp'], true)) {
                    continue;
                }
                $params[$k] = is_scalar($v) || $v === null ? $v : json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
    
    
            // Normalize pagination params for specific Lazada endpoints
            if ($apiPath === '/category/brands/query') {
                // Lazada requires startRow + pageSize (not page_no/page_size)
                $pageNo = isset($params['page_no']) ? (int)$params['page_no'] : null;
                $pageSize = isset($params['page_size']) ? (int)$params['page_size'] : null;
    
                if ($pageNo !== null && $pageSize !== null) {
                    $params['startRow'] = max(0, ($pageNo - 1) * $pageSize);
                    $params['pageSize'] = max(1, $pageSize);
                    unset($params['page_no'], $params['page_size']);
                } else {
                    // if client already provided correct keys, ensure casing matches Lazada
                    if (isset($params['startRow']) && !isset($params['pageSize']) && isset($params['page_size'])) {
                        $params['pageSize'] = (int)$params['page_size'];
                        unset($params['page_size']);
                    }
                }
            }
    
            // Lazada is very strict with call frequency (SellerCallLimit ~1s bans).
            // Enforce a minimum spacing between calls (per region/app_key) and retry with backoff.
            $method = strtoupper($method);

            $rateKey = 'lazada_api_last_call:' . $region . ':' . $appKey;
            $lockKey = 'lazada_api_lock:' . $region . ':' . $appKey;
            $minIntervalMs = 1200;

            $callOnce = function () use ($client, $region, $apiPath, &$params, $appSecret, $method) {
                $params['sign'] = $client->sign($apiPath, $params, $appSecret);
                return $method === 'POST'
                    ? $client->post($region, $apiPath, $params)
                    : $client->get($region, $apiPath, $params);
            };

            $result = null;
            $maxAttempts = 6;
            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                $lock = null;
                try {
                    if (method_exists(Cache::class, 'lock')) {
                        $lock = Cache::lock($lockKey, 15);
                        $lock->block(15);
                    }

                    $nowMs = (int) round(microtime(true) * 1000);
                    $lastMs = (int) Cache::get($rateKey, 0);
                    $waitMs = $minIntervalMs - ($nowMs - $lastMs);
                    if ($waitMs > 0) {
                        usleep($waitMs * 1000);
                    }

                    $result = $callOnce();
                    Cache::put($rateKey, (int) round(microtime(true) * 1000), 60);
                } finally {
                    if ($lock) {
                        try { $lock->release(); } catch (\Throwable $e) {}
                    }
                }

                $body = $result['body'] ?? null;
                $code = is_array($body) ? ($body['code'] ?? null) : null;
                if ($code === 'SellerCallLimit') {
                    // Backoff a little longer than the ban duration and retry.
                    usleep((1300 + ($attempt - 1) * 350) * 1000);
                    continue;
                }

                break;
            }

            if ($result === null) {
                $result = ['status' => 0, 'ok' => false, 'body' => ['message' => 'API call failed']];
            }
    
            LazadaApiLog::safeCreate([
                'pack' => $pack,
                'method' => $method,
                'api_path' => $apiPath,
                'auth_required' => $authRequired,
                'request_params' => $params,
                'response_status' => (int)($result['status'] ?? 0),
                'ok' => (bool)($result['ok'] ?? false),
                'response_body' => $result['body'] ?? null,
                'user_id' => auth()->id(),
            ]);
    
            return $result;
        }


    /**
     * Lazada sometimes returns timestamps as ISO strings or numeric epoch values (seconds/ms).
     * Normalize to 'Y-m-d H:i:s' or null.
     */
    private function parseApiDatetime($value): ?string
    {
        if ($value === null) return null;

        // If value is already a DateTime-like object
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        // Numeric epoch
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $num = (int)$value;
            // If milliseconds
            if ($num > 1000000000000) {
                $num = (int) floor($num / 1000);
            }
            if ($num > 0) {
                return date('Y-m-d H:i:s', $num);
            }
            return null;
        }

        $str = trim((string)$value);
        if ($str === '') return null;

        $ts = strtotime($str);
        if ($ts === false) {
            return null;
        }
        return date('Y-m-d H:i:s', $ts);
    }


    /**
     * Keep user on the same orders tab after POST actions (Pack/RTS/Cancel/etc.).
     * Uses HTTP referer when available; falls back to orders index with current query params.
     */
    private function redirectBackOrIndex(Request $request)
    {
        $prev = url()->previous();
        if (is_string($prev) && $prev !== '') {
            return redirect()->to($prev);
        }

        $qs = [];
        foreach (['tab','pending_sub','order_number','buyer_name','per_page','page','date_from','date_to','status'] as $k) {
            if ($request->filled($k)) {
                $qs[$k] = $request->input($k);
            }
        }

        return redirect()->route('lazada.orders.index', $qs);
    }

function decryptSetting(?LazadaSetting $setting): ?object
    {
        if (!$setting) {
            return null;
        }

        $s = (object) $setting->toArray();

        foreach (['app_secret', 'auth_code', 'access_token', 'refresh_token'] as $k) {
            if (!empty($s->$k)) {
                try {
                    $s->$k = decrypt($s->$k);
                } catch (\Throwable $e) {
                    // Value may already be plaintext (not encrypted). Keep as-is.
                    // (This avoids losing credentials when older installs stored tokens without encrypting.)
                    $s->$k = $s->$k;
                }
            }
        }

        return $s;
    }
}