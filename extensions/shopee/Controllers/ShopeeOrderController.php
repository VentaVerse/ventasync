<?php

namespace Extensions\shopee\Controllers;

use App\Http\Controllers\Controller;

use Extensions\shopee\Models\ShopeeApiLog;
use Extensions\shopee\Models\ShopeeOrder;
use Extensions\shopee\Models\ShopeeOrderProduct;
use Extensions\shopee\Models\ShopeeReturn;
use Extensions\shopee\Models\ShopeeSetting;
use App\Services\ActivityLogger;
use Extensions\shopee\Services\Shopee\ShopeeClient;
use Extensions\shopee\Services\ShopeeCatalogOrderSync;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ShopeeOrderController extends Controller
{
    public function index(Request $request)
    {
        $setting = ShopeeSetting::query()->first();

        $filters = $request->validate([
            'order_number' => ['nullable', 'string', 'max:80'],
            'buyer_name' => ['nullable', 'string', 'max:120'],
        ]);

        $orderNumber = trim((string) ($filters['order_number'] ?? ''));
        $buyerName = trim((string) ($filters['buyer_name'] ?? ''));

        $perPage = (int) $request->query('per_page', 10);
        if (!in_array($perPage, [10, 20, 50], true)) {
            $perPage = 10;
        }

        $tab = strtoupper((string) $request->query('tab', 'PENDING'));

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
            'FAILED_DELIVERY',
        ];
        if (!in_array($tab, $allowedTabs, true)) {
            $tab = 'ALL';
        }

        $defaultDir = ($tab === 'PENDING') ? 'asc' : 'desc';
        $sortKey = strtolower((string) $request->query('sort', ''));
        if ($sortKey === '') {
            $sortKey = 'created_' . $defaultDir;
        }
        $allowedSort = [
            'created_asc',
            'created_desc',
            'confirmed_pay_asc',
            'confirmed_pay_desc',
            'confirmed_update_asc',
            'confirmed_update_desc',
            'confirmed_create_asc',
            'confirmed_create_desc',
        ];
        if (!in_array($sortKey, $allowedSort, true)) {
            $sortKey = 'created_' . $defaultDir;
        }
        if (!preg_match('/^(.*)_(asc|desc)$/', $sortKey, $m)) {
            $sortBy = 'created';
            $sortDir = $defaultDir;
        } else {
            $sortBy = $m[1];
            $sortDir = $m[2];
        }

        $tabStatusMap = [
            'UNPAID'              => ['UNPAID'],
            'PENDING'             => ['READY_TO_SHIP', 'PROCESSED'],
            'SHIPPING'            => ['SHIPPED', 'TO_CONFIRM_RECEIVE'],
            'DELIVERED_COMPLETED' => ['COMPLETED'],
            'CANCELLED'           => ['CANCELLED', 'IN_CANCEL'],
            'FAILED_DELIVERY'     => ['RETRY_SHIP'],
        ];

        $tabs = [
            'ALL'                 => 'All',
            'UNPAID'              => 'Unpaid',
            'PENDING'             => 'To Ship',
            'SHIPPING'            => 'Shipping',
            'DELIVERED_COMPLETED' => 'Delivered',
            'CANCELLED'           => 'Cancelled',
            'FAILED_DELIVERY'     => 'Failed Delivery',
        ];

        $query = ShopeeOrder::query()
            ->where('region', $setting->region ?? 'ph')
            ->with('products');

        $baseCountQuery = ShopeeOrder::query()->where('region', $setting->region ?? 'ph');

        if ($tab !== 'ALL') {
            if ($tab === 'PENDING' && $pendingSubtab !== '') {
                $pendingMap = [
                    'to_pack'     => ['READY_TO_SHIP'],
                    'to_handover' => ['PROCESSED'],
                ];
                $statuses = $pendingMap[$pendingSubtab] ?? ['READY_TO_SHIP'];
                $query->whereIn('status', $statuses);
            } else {
                $statuses = $tabStatusMap[$tab] ?? [];
                if (!empty($statuses)) {
                    $query->whereIn('status', $statuses);
                }
            }
        }

        $pending_sub_counts = [
            'to_pack'     => (clone $baseCountQuery)->whereIn('status', ['READY_TO_SHIP'])->count(),
            'to_handover' => (clone $baseCountQuery)->whereIn('status', ['PROCESSED'])->count(),
        ];

        if ($orderNumber !== '') {
            $query->where('order_sn', 'like', '%' . $orderNumber . '%');
        }

        if ($buyerName !== '') {
            $query->where(function ($q) use ($buyerName) {
                $like = '%' . $buyerName . '%';
                $q->where('raw->buyer_username', 'like', $like)
                    ->orWhere('raw->buyer_user_name', 'like', $like);
            });
        }

        $tab_counts = [];
        $tab_counts['ALL'] = (clone $baseCountQuery)->count();
        foreach ($tabStatusMap as $k => $statuses) {
            if (!empty($statuses)) {
                if ($k === 'PENDING') {
                    $tab_counts[$k] = (clone $baseCountQuery)->whereIn('status', ['READY_TO_SHIP'])->count();
                } else {
                    $tab_counts[$k] = (clone $baseCountQuery)->whereIn('status', $statuses)->count();
                }
            } else {
                $tab_counts[$k] = 0;
            }
        }

        if ($sortBy === 'confirmed_pay') {
            $query->orderByRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(raw, '$.pay_time')) AS UNSIGNED) {$sortDir}");
        } elseif ($sortBy === 'confirmed_update') {
            $query->orderByRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(raw, '$.update_time')) AS UNSIGNED) {$sortDir}");
        } elseif ($sortBy === 'confirmed_create') {
            $query->orderByRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(raw, '$.create_time')) AS UNSIGNED) {$sortDir}");
        }
        $orders = $query
            ->orderBy('order_created_at', $sortDir)
            ->orderBy('created_at', $sortDir)
            ->paginate($perPage)
            ->appends($request->query());

        // Detect which orders have saved AWB PDFs
        $awbDir = storage_path('app/shopee-awb');
        $savedAwbs = [];
        foreach ($orders as $o) {
            $sn = preg_replace('/[^A-Za-z0-9_-]/', '', $o->order_sn ?? '');
            if ($sn !== '' && file_exists($awbDir . '/' . $sn . '.pdf')) {
                $savedAwbs[$o->order_sn] = true;
            }
        }

        return view('ext-shopee::orders.index', [
            'setting' => $setting,
            'orders' => $orders,
            'per_page' => $perPage,
            'last_result' => session('shopee_orders_last_result'),
            'tabs' => $tabs,
            'active_tab' => $tab,
            'pending_subtab' => $pendingSubtab,
            'tab_counts' => $tab_counts,
            'pending_sub_counts' => $pending_sub_counts,
            'pending_courier_counts' => $this->buildToShipCourierCounts($setting->region ?? 'ph'),
            'savedAwbs' => $savedAwbs,
            'sort' => $sortKey,
            'filters' => [
                'order_number' => $orderNumber,
                'buyer_name' => $buyerName,
            ],
        ]);
    }

    private function buildToShipCourierCounts(string $region): array
    {
        $counts = [];
        $display = [];
        $statuses = ['PROCESSED'];

        ShopeeOrder::query()
            ->where('region', $region)
            ->whereIn('status', $statuses)
            ->select(['id', 'raw'])
            ->orderBy('id')
            ->chunk(300, function ($orders) use (&$counts, &$display) {
                foreach ($orders as $o) {
                    $courier = $this->extractShopeeCourier($o);
                    $label = $courier !== '' ? $courier : 'Unknown';
                    $key = mb_strtolower($label);
                    if (!isset($display[$key])) {
                        $display[$key] = $label;
                    }
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                }
            });

        arsort($counts);
        $out = [];
        foreach ($counts as $key => $count) {
            $out[$display[$key] ?? $key] = $count;
        }

        return $out;
    }

    private function extractShopeeCourier(ShopeeOrder $order): string
    {
        $raw = is_array($order->raw) ? $order->raw : [];
        foreach (['shipping_carrier', 'checkout_shipping_carrier'] as $k) {
            $v = $raw[$k] ?? null;
            if (is_array($v)) {
                $v = implode(', ', $v);
            }
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }

        return '';
    }

    public function returns(Request $request)
    {
        $setting = ShopeeSetting::query()->first();

        $filters = $request->validate([
            'return_sn' => ['nullable', 'string', 'max:80'],
            'order_sn'  => ['nullable', 'string', 'max:80'],
        ]);

        $returnSn = trim((string) ($filters['return_sn'] ?? ''));
        $orderSn  = trim((string) ($filters['order_sn'] ?? ''));

        $perPage = (int) $request->query('per_page', 10);
        if (!in_array($perPage, [10, 20, 50], true)) {
            $perPage = 10;
        }

        $tab = strtoupper((string) $request->query('tab', 'ALL'));

        $tabStatusMap = [
            'REQUESTED' => ['REQUESTED', 'PROCESSING'],
            'ACCEPTED'  => ['ACCEPTED'],
            'REFUND'    => ['REFUND_PAID', 'SELLER_COMPENSATION'],
            'DISPUTE'   => ['JUDGING', 'SELLER_DISPUTE'],
            'CLOSED'    => ['CLOSED', 'CANCELLED'],
        ];

        $tabs = [
            'ALL'       => 'All',
            'REQUESTED' => 'Under Review',
            'ACCEPTED'  => 'Returning',
            'REFUND'    => 'Refunded',
            'DISPUTE'   => 'Disputed',
            'CLOSED'    => 'Rejected / Cancelled',
        ];

        if (!array_key_exists($tab, $tabs)) {
            $tab = 'ALL';
        }

        $region = $setting->region ?? 'ph';
        $query = ShopeeReturn::query()->where('region', $region);
        $baseCountQuery = ShopeeReturn::query()->where('region', $region);

        if ($tab !== 'ALL') {
            $statuses = $tabStatusMap[$tab] ?? [];
            if (!empty($statuses)) {
                $query->whereIn('status', $statuses);
            }
        }

        if ($returnSn !== '') {
            $query->where('return_sn', 'like', '%' . $returnSn . '%');
        }
        if ($orderSn !== '') {
            $query->where('order_sn', 'like', '%' . $orderSn . '%');
        }

        $tab_counts = [];
        $tab_counts['ALL'] = (clone $baseCountQuery)->count();
        foreach ($tabStatusMap as $k => $statuses) {
            $tab_counts[$k] = (clone $baseCountQuery)->whereIn('status', $statuses)->count();
        }

        $returns = $query
            ->orderByDesc('return_updated_at')
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->appends($request->query());

        return view('ext-shopee::orders.returns', [
            'setting'    => $setting,
            'returns'    => $returns,
            'per_page'   => $perPage,
            'last_result' => session('shopee_orders_last_result'),
            'tabs'       => $tabs,
            'active_tab' => $tab,
            'tab_counts' => $tab_counts,
            'filters'    => [
                'return_sn' => $returnSn,
                'order_sn'  => $orderSn,
            ],
        ]);
    }

    public function show(Request $request, ShopeeClient $client, string $orderSn)
    {
        $orderSn = trim($orderSn);
        if ($orderSn === '' || mb_strlen($orderSn) > 80) {
            abort(404);
        }

        $settingRaw = ShopeeSetting::query()->first();
        $region = $settingRaw->region ?? 'ph';

        $order = ShopeeOrder::query()
            ->where('region', $region)
            ->where('order_sn', $orderSn)
            ->with('products')
            ->firstOrFail();

        $apiError = null;

        $refresh = (bool) $request->boolean('refresh');
        $setting = $settingRaw?->decrypted();
        $hasCreds = $setting && $setting->partner_id && $setting->partner_key && $setting->access_token && $setting->shop_id;

        if ($hasCreds && $refresh) {
            try {
                $res = $client->shopGet(
                    $setting->mode ?? 'live',
                    (int) $setting->partner_id,
                    (string) $setting->partner_key,
                    (string) $setting->access_token,
                    (int) $setting->shop_id,
                    '/api/v2/order/get_order_detail',
                    [
                        'order_sn_list' => $orderSn,
                        'response_optional_fields' => 'buyer_username,recipient_address,total_amount,item_list,pay_time,shipping_carrier,tracking_no,payment_method,order_chargeable_weight_gram,note,currency',
                    ]
                );

                ShopeeApiLog::safeCreate([
                    'pack'            => 'shopee.order.detail.refresh',
                    'method'          => 'GET',
                    'api_path'        => '/api/v2/order/get_order_detail',
                    'auth_required'   => true,
                    'request_params'  => ['order_sn' => $orderSn],
                    'response_status' => (int) ($res['status'] ?? 0),
                    'ok'              => (bool) ($res['ok'] ?? false),
                    'response_body'   => $res['body'] ?? null,
                ]);

                if (($res['ok'] ?? false) && is_array($res['body'])) {
                    $respData = $res['body']['response'] ?? $res['body'];
                    $orderList = $respData['order_list'] ?? [];
                    if (!empty($orderList) && is_array($orderList[0] ?? null)) {
                        $detail = $orderList[0];

                        // Fetch tracking number if not in detail
                        if (empty($detail['tracking_no'])) {
                            try {
                                $trackRes = $client->shopGet(
                                    $setting->mode ?? 'live',
                                    (int) $setting->partner_id,
                                    (string) $setting->partner_key,
                                    (string) $setting->access_token,
                                    (int) $setting->shop_id,
                                    '/api/v2/logistics/get_tracking_number',
                                    ['order_sn' => $orderSn]
                                );
                                $trackNo = trim((string) (($trackRes['body']['response'] ?? [])['tracking_number'] ?? ''));
                                if ($trackNo !== '') {
                                    $detail['tracking_no'] = $trackNo;
                                }
                            } catch (\Throwable $e) {}
                        }

                        $order->raw = $detail;
                        $order->status = $detail['order_status'] ?? $order->status;
                        $order->save();

                        // Sync items from detail
                        $this->syncOrderProductsFromDetail($order, $detail);
                        $order->load('products');
                    }
                } else {
                    $apiError = $res;
                }
            } catch (\Throwable $e) {
                $apiError = ['exception' => $e->getMessage()];
            }
        }

        // Fetch escrow detail (fees) if not already stored or on refresh
        if ($hasCreds && (empty($order->fees) || $refresh)) {
            try {
                $escrowRes = $client->shopGet(
                    $setting->mode ?? 'live',
                    (int) $setting->partner_id,
                    (string) $setting->partner_key,
                    (string) $setting->access_token,
                    (int) $setting->shop_id,
                    '/api/v2/payment/get_escrow_detail',
                    ['order_sn' => $orderSn]
                );

                ShopeeApiLog::safeCreate([
                    'pack'            => 'shopee.payment.get_escrow_detail',
                    'method'          => 'GET',
                    'api_path'        => '/api/v2/payment/get_escrow_detail',
                    'auth_required'   => true,
                    'request_params'  => ['order_sn' => $orderSn],
                    'response_status' => (int) ($escrowRes['status'] ?? 0),
                    'ok'              => (bool) ($escrowRes['ok'] ?? false),
                    'response_body'   => is_array($escrowRes['body'] ?? null) ? $escrowRes['body'] : null,
                ]);

                if (($escrowRes['ok'] ?? false) && is_array($escrowRes['body'] ?? null)) {
                    $escrowBody = $escrowRes['body'];
                    $escrowData = $escrowBody['response'] ?? $escrowBody;
                    if (is_array($escrowData) && !empty($escrowData)) {
                        // Extract order_income (the financial breakdown) as the top-level fees
                        $orderIncome = $escrowData['order_income'] ?? null;
                        $order->fees = is_array($orderIncome) && !empty($orderIncome)
                            ? $orderIncome
                            : $escrowData;
                        $order->save();
                    }
                }
            } catch (\Throwable $e) {
                // Best-effort — don't fail the page
            }
        }

        $returns = ShopeeReturn::query()
            ->where('region', $region)
            ->where('order_sn', $orderSn)
            ->orderByDesc('return_created_at')
            ->get();

        return view('ext-shopee::orders.show', [
            'order' => $order,
            'setting_region' => $region,
            'api_error' => $apiError,
            'returns' => $returns,
        ]);
    }

    public function fetch(Request $request, ShopeeClient $client)
    {
        try { @set_time_limit(0); } catch (\Throwable $e) {}

        $data = $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'no_stock' => 'nullable',
        ]);

        $skipStock = !empty($data['no_stock']);

        $setting = ShopeeSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->partner_id || !$setting->partner_key || !$setting->access_token || !$setting->shop_id) {
            return redirect()->route('ext.shopee.orders.index')->with('shopee_orders_last_result', [
                'ok' => false,
                'message' => 'Missing Shopee credentials/token. Please configure Shopee settings first.',
            ]);
        }

        $tz = new \DateTimeZone('Asia/Manila');

        $timeFrom = (new \DateTime($data['date_from'], $tz))->setTime(0, 0, 0)->getTimestamp();
        $timeTo = (new \DateTime($data['date_to'], $tz))->setTime(23, 59, 59)->getTimestamp();
        $timeRangeField = 'create_time';

        // Shopee cursor-based pagination with sliding 15-day windows
        // (Shopee API max window is 15 days per request)
        $allOrderSns = [];
        $pageSize = 100;
        $maxOrders = 5000;
        $windowSize = 15 * 86400; // 15 days in seconds

        $windowFrom = $timeFrom;
        while ($windowFrom < $timeTo && count($allOrderSns) < $maxOrders) {
            $windowTo = min($windowFrom + $windowSize, $timeTo);
            $cursor = '';

            while (true) {
                $extraQuery = [
                    'time_range_field' => $timeRangeField,
                    'time_from'        => $windowFrom,
                    'time_to'          => $windowTo,
                    'page_size'        => $pageSize,
                ];
                if ($cursor !== '') {
                    $extraQuery['cursor'] = $cursor;
                }

                $res = $client->shopGet(
                    $setting->mode ?? 'live',
                    (int) $setting->partner_id,
                    (string) $setting->partner_key,
                    (string) $setting->access_token,
                    (int) $setting->shop_id,
                    '/api/v2/order/get_order_list',
                    $extraQuery
                );

                ShopeeApiLog::safeCreate([
                    'pack'            => 'shopee.order.get_order_list',
                    'method'          => 'GET',
                    'api_path'        => '/api/v2/order/get_order_list',
                    'auth_required'   => true,
                    'request_params'  => $extraQuery,
                    'response_status' => (int) ($res['status'] ?? 0),
                    'ok'              => (bool) ($res['ok'] ?? false),
                    'response_body'   => $res['body'] ?? null,
                ]);

                if (!($res['ok'] ?? false)) {
                    $msg = 'Shopee API error';
                    $body = $res['body'] ?? [];
                    if (is_array($body)) {
                        $msg = (string) ($body['message'] ?? ($body['msg'] ?? $msg));
                    }
                    return redirect()->route('ext.shopee.orders.index')->with('shopee_orders_last_result', [
                        'ok' => false,
                        'message' => $msg,
                    ]);
                }

                $body = $res['body'] ?? [];
                $respData = $body['response'] ?? $body;
                $orderList = $respData['order_list'] ?? [];
                if (!is_array($orderList)) $orderList = [];

                foreach ($orderList as $o) {
                    $sn = (string) ($o['order_sn'] ?? '');
                    if ($sn !== '') {
                        $allOrderSns[] = $sn;
                    }
                }

                $more = (bool) ($respData['more'] ?? false);
                $cursor = (string) ($respData['next_cursor'] ?? '');

                if (!$more || $cursor === '' || count($allOrderSns) >= $maxOrders) {
                    break;
                }
            }

            $windowFrom = $windowTo;
        }

        if (empty($allOrderSns)) {
            return redirect()->route('ext.shopee.orders.index')->with('shopee_orders_last_result', [
                'ok' => true,
                'message' => 'No orders found in the given time range.',
            ]);
        }

        // Fetch order details in batches of 50
        $saved = 0;
        $skipped = 0;
        $errors = 0;

        foreach (array_chunk($allOrderSns, 50) as $chunk) {
            $detailRes = $client->shopGet(
                $setting->mode ?? 'live',
                (int) $setting->partner_id,
                (string) $setting->partner_key,
                (string) $setting->access_token,
                (int) $setting->shop_id,
                '/api/v2/order/get_order_detail',
                [
                    'order_sn_list' => implode(',', $chunk),
                    'response_optional_fields' => 'buyer_username,recipient_address,total_amount,item_list,pay_time,shipping_carrier,tracking_no,payment_method,order_chargeable_weight_gram,note,currency',
                ]
            );

            ShopeeApiLog::safeCreate([
                'pack'            => 'shopee.order.get_order_detail',
                'method'          => 'GET',
                'api_path'        => '/api/v2/order/get_order_detail',
                'auth_required'   => true,
                'request_params'  => ['order_sn_list' => implode(',', $chunk)],
                'response_status' => (int) ($detailRes['status'] ?? 0),
                'ok'              => (bool) ($detailRes['ok'] ?? false),
                'response_body'   => $detailRes['body'] ?? null,
            ]);

            if (!($detailRes['ok'] ?? false)) {
                $errors += count($chunk);
                continue;
            }

            $detailBody = $detailRes['body'] ?? [];
            $detailResp = $detailBody['response'] ?? $detailBody;
            $detailList = $detailResp['order_list'] ?? [];
            if (!is_array($detailList)) $detailList = [];

            foreach ($detailList as $o) {
                if (!is_array($o)) continue;

                $orderSn = (string) ($o['order_sn'] ?? '');
                if ($orderSn === '') continue;

                $existing = ShopeeOrder::query()
                    ->where('region', $setting->region ?? 'ph')
                    ->where('order_sn', $orderSn)
                    ->first();

                if ($existing) {
                    $skipped++;
                    continue;
                }

                $status = (string) ($o['order_status'] ?? '');
                $createdAt = $this->parseTimestamp($o['create_time'] ?? null);
                $updatedAt = $this->parseTimestamp($o['update_time'] ?? null);

                try {
                    $newOrder = ShopeeOrder::query()->create([
                        'region'           => $setting->region ?? 'ph',
                        'order_sn'         => $orderSn,
                        'status'           => $status,
                        'order_created_at' => $createdAt,
                        'order_updated_at' => $updatedAt,
                        'raw'              => $o,
                    ]);
                    $saved++;

                    // Sync items
                    try {
                        $this->syncOrderProductsFromDetail($newOrder, $o);
                    } catch (\Throwable $e) {}

                    // Sync to catalog
                    try {
                        (new ShopeeCatalogOrderSync)->setSkipStockAdjust($skipStock)->sync($newOrder);
                    } catch (\Throwable $e) {}
                } catch (\Throwable $e) {
                    $errors++;
                }
            }
        }

        $finalMessage = ($saved > 0)
            ? "Fetched {$saved} new order(s)." . ($skipped > 0 ? " {$skipped} existing skipped." : '')
            : 'No new orders found.' . ($skipped > 0 ? " {$skipped} existing skipped." : '');

        return redirect()->route('ext.shopee.orders.index')->with('shopee_orders_last_result', [
            'ok' => true,
            'message' => $finalMessage,
        ]);
    }

    public function updateStatuses(Request $request, ShopeeClient $client)
    {
        try { @set_time_limit(0); } catch (\Throwable $e) {}

        $data = $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
        ]);

        $setting = ShopeeSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->partner_id || !$setting->partner_key || !$setting->access_token || !$setting->shop_id) {
            return redirect()->route('ext.shopee.orders.index')->with('shopee_orders_last_result', [
                'ok' => false,
                'message' => 'Missing Shopee credentials/token.',
            ]);
        }

        $tz = new \DateTimeZone('Asia/Manila');
        $timeFrom = (new \DateTime($data['date_from'], $tz))->setTime(0, 0, 0)->getTimestamp();
        $timeTo = (new \DateTime($data['date_to'], $tz))->setTime(23, 59, 59)->getTimestamp();

        // Sliding 15-day windows (Shopee API max window is 15 days per request)
        $allOrderSns = [];
        $maxOrders = 5000;
        $windowSize = 15 * 86400; // 15 days in seconds

        $windowFrom = $timeFrom;
        while ($windowFrom < $timeTo && count($allOrderSns) < $maxOrders) {
            $windowTo = min($windowFrom + $windowSize, $timeTo);
            $cursor = '';

            while (true) {
                $extraQuery = [
                    'time_range_field' => 'update_time',
                    'time_from'        => $windowFrom,
                    'time_to'          => $windowTo,
                    'page_size'        => 100,
                ];
                if ($cursor !== '') {
                    $extraQuery['cursor'] = $cursor;
                }

                $res = $client->shopGet(
                    $setting->mode ?? 'live',
                    (int) $setting->partner_id,
                    (string) $setting->partner_key,
                    (string) $setting->access_token,
                    (int) $setting->shop_id,
                    '/api/v2/order/get_order_list',
                    $extraQuery
                );

                if (!($res['ok'] ?? false)) break 2;

                $body = $res['body'] ?? [];
                $respData = $body['response'] ?? $body;
                $orderList = $respData['order_list'] ?? [];
                if (!is_array($orderList)) $orderList = [];

                foreach ($orderList as $o) {
                    $sn = (string) ($o['order_sn'] ?? '');
                    if ($sn !== '') {
                        $allOrderSns[] = $sn;
                    }
                }

                $more = (bool) ($respData['more'] ?? false);
                $cursor = (string) ($respData['next_cursor'] ?? '');

                if (!$more || $cursor === '' || count($allOrderSns) >= $maxOrders) {
                    break;
                }
            }

            $windowFrom = $windowTo;
        }

        // Fetch details in batches
        $updated = 0;
        $skipped = 0;

        foreach (array_chunk($allOrderSns, 50) as $chunk) {
            $detailRes = $client->shopGet(
                $setting->mode ?? 'live',
                (int) $setting->partner_id,
                (string) $setting->partner_key,
                (string) $setting->access_token,
                (int) $setting->shop_id,
                '/api/v2/order/get_order_detail',
                [
                    'order_sn_list' => implode(',', $chunk),
                    'response_optional_fields' => 'buyer_username,recipient_address,total_amount,item_list,pay_time,shipping_carrier,tracking_no,payment_method,currency',
                ]
            );

            if (!($detailRes['ok'] ?? false)) continue;

            $detailBody = $detailRes['body'] ?? [];
            $detailResp = $detailBody['response'] ?? $detailBody;
            $detailList = $detailResp['order_list'] ?? [];
            if (!is_array($detailList)) $detailList = [];

            foreach ($detailList as $o) {
                if (!is_array($o)) continue;

                $orderSn = (string) ($o['order_sn'] ?? '');
                if ($orderSn === '') continue;

                $existing = ShopeeOrder::query()
                    ->where('region', $setting->region ?? 'ph')
                    ->where('order_sn', $orderSn)
                    ->first();

                if (!$existing) {
                    $skipped++;
                    continue;
                }

                $existing->fill([
                    'status'           => (string) ($o['order_status'] ?? $existing->status),
                    'order_created_at' => $this->parseTimestamp($o['create_time'] ?? null) ?? $existing->order_created_at,
                    'order_updated_at' => $this->parseTimestamp($o['update_time'] ?? null) ?? $existing->order_updated_at,
                    'raw'              => $o,
                ])->save();
                $updated++;

                try {
                    $this->syncOrderProductsFromDetail($existing, $o);
                } catch (\Throwable $e) {}

                try {
                    (new ShopeeCatalogOrderSync)->sync($existing);
                } catch (\Throwable $e) {}
            }
        }

        $message = ($updated === 0 && $skipped === 0)
            ? 'Orders are already up to date.'
            : "Updated {$updated} order(s)." . ($skipped > 0 ? " Skipped {$skipped} not-yet-fetched." : '');

        ActivityLogger::log('updated', 'Shopee Order', null, 'Synced statuses for ' . $updated . ' order(s)');

        return redirect()->route('ext.shopee.orders.index')->with('shopee_orders_last_result', [
            'ok' => true,
            'message' => $message,
        ]);
    }

    public function getShippingAddresses(ShopeeClient $client, string $orderSn)
    {
        $setting = ShopeeSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->partner_id || !$setting->partner_key || !$setting->access_token || !$setting->shop_id) {
            return response()->json(['ok' => false, 'message' => 'Missing Shopee credentials.'], 422);
        }

        $res = $client->shopGet(
            $setting->mode ?? 'live',
            (int) $setting->partner_id,
            (string) $setting->partner_key,
            (string) $setting->access_token,
            (int) $setting->shop_id,
            '/api/v2/logistics/get_shipping_parameter',
            ['order_sn' => $orderSn]
        );

        ShopeeApiLog::safeCreate([
            'pack'            => 'shopee.logistics.get_shipping_parameter.addresses',
            'method'          => 'GET',
            'api_path'        => '/api/v2/logistics/get_shipping_parameter',
            'auth_required'   => true,
            'request_params'  => ['order_sn' => $orderSn],
            'response_status' => (int) ($res['status'] ?? 0),
            'ok'              => (bool) ($res['ok'] ?? false),
            'response_body'   => $res['body'] ?? null,
        ]);

        if (!($res['ok'] ?? false)) {
            return response()->json(['ok' => false, 'message' => 'Failed to get shipping parameters.']);
        }

        $paramBody = $res['body'] ?? [];
        $paramResp = $paramBody['response'] ?? $paramBody;

        return response()->json([
            'ok'      => true,
            'pickup'  => $paramResp['pickup'] ?? null,
            'dropoff' => $paramResp['dropoff'] ?? null,
        ]);
    }

    public function shipOrder(Request $request, ShopeeClient $client, string $orderSn)
    {
        $json = $request->expectsJson();

        $setting = ShopeeSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->partner_id || !$setting->partner_key || !$setting->access_token || !$setting->shop_id) {
            if ($json) return response()->json(['ok' => false, 'message' => 'Missing Shopee credentials/token.'], 422);
            return redirect()->route('ext.shopee.orders.index')->with('shopee_orders_last_result', [
                'ok' => false,
                'message' => 'Missing Shopee credentials/token.',
            ]);
        }

        $order = ShopeeOrder::query()
            ->where('region', $setting->region ?? 'ph')
            ->where('order_sn', $orderSn)
            ->first();

        if (!$order) {
            if ($json) return response()->json(['ok' => false, 'message' => 'Order not found.'], 404);
            return redirect()->route('ext.shopee.orders.index')->with('shopee_orders_last_result', [
                'ok' => false,
                'message' => 'Order not found.',
            ]);
        }

        // Step 1: Get shipping parameter
        $paramRes = $client->shopGet(
            $setting->mode ?? 'live',
            (int) $setting->partner_id,
            (string) $setting->partner_key,
            (string) $setting->access_token,
            (int) $setting->shop_id,
            '/api/v2/logistics/get_shipping_parameter',
            ['order_sn' => $orderSn]
        );

        ShopeeApiLog::safeCreate([
            'pack'            => 'shopee.logistics.get_shipping_parameter',
            'method'          => 'GET',
            'api_path'        => '/api/v2/logistics/get_shipping_parameter',
            'auth_required'   => true,
            'request_params'  => ['order_sn' => $orderSn],
            'response_status' => (int) ($paramRes['status'] ?? 0),
            'ok'              => (bool) ($paramRes['ok'] ?? false),
            'response_body'   => $paramRes['body'] ?? null,
        ]);

        if (!($paramRes['ok'] ?? false)) {
            if ($json) return response()->json(['ok' => false, 'message' => 'Failed to get shipping parameters.'], 422);
            return redirect()->route('ext.shopee.orders.index')->with('shopee_orders_last_result', [
                'ok' => false,
                'message' => 'Failed to get shipping parameters.',
                'raw' => $paramRes,
            ]);
        }

        $paramBody = $paramRes['body'] ?? [];
        $paramResp = $paramBody['response'] ?? $paramBody;
        $infoList = $paramResp['info_needed'] ?? [];
        $pickup = $paramResp['pickup'] ?? null;
        $dropoff = $paramResp['dropoff'] ?? null;

        // Step 2: Ship the order using user-selected shipping type
        $shippingType = $request->input('shipping_type', 'pickup');
        $shipBody = ['order_sn' => $orderSn];

        if ($shippingType === 'dropoff') {
            $branchId = (int) $request->input('branch_id', 0);
            $shipBody['dropoff'] = $branchId > 0 ? ['branch_id' => $branchId] : new \stdClass();
        } else {
            // Use user-selected address_id if provided, otherwise find pickup_address flag
            $addressId = (int) $request->input('address_id', 0);
            if ($addressId === 0) {
                $addressList = ($paramResp['pickup']['address_list'] ?? []);
                foreach ($addressList as $addr) {
                    if (is_array($addr) && in_array('pickup_address', $addr['address_flag'] ?? [])) {
                        $addressId = (int) ($addr['address_id'] ?? 0);
                        break;
                    }
                }
                if ($addressId === 0 && !empty($addressList) && is_array($addressList[0] ?? null)) {
                    $addressId = (int) ($addressList[0]['address_id'] ?? 0);
                }
            }
            $shipBody['pickup'] = [
                'address_id' => $addressId,
            ];
        }

        $shipRes = $client->shopPost(
            $setting->mode ?? 'live',
            (int) $setting->partner_id,
            (string) $setting->partner_key,
            (string) $setting->access_token,
            (int) $setting->shop_id,
            '/api/v2/logistics/ship_order',
            [],
            $shipBody
        );

        ShopeeApiLog::safeCreate([
            'pack'            => 'shopee.logistics.ship_order',
            'method'          => 'POST',
            'api_path'        => '/api/v2/logistics/ship_order',
            'auth_required'   => true,
            'request_params'  => $shipBody,
            'response_status' => (int) ($shipRes['status'] ?? 0),
            'ok'              => (bool) ($shipRes['ok'] ?? false),
            'response_body'   => $shipRes['body'] ?? null,
        ]);

        $shipBodyResp = $shipRes['body'] ?? [];
        $errMsg = $shipBodyResp['error'] ?? ($shipBodyResp['message'] ?? null);

        if (!($shipRes['ok'] ?? false) || (is_string($errMsg) && $errMsg !== '' && $errMsg !== 'success')) {
            if ($json) return response()->json(['ok' => false, 'message' => 'Ship order failed: ' . ($errMsg ?? 'Unknown error')], 422);
            return redirect()->route('ext.shopee.orders.index')->with('shopee_orders_last_result', [
                'ok' => false,
                'message' => 'Ship order failed: ' . ($errMsg ?? 'Unknown error'),
                'raw' => $shipRes,
            ]);
        }

        // Update local status
        $order->status = 'PROCESSED';
        $order->save();

        try {
            (new ShopeeCatalogOrderSync)->sync($order);
        } catch (\Throwable $e) {}

        ActivityLogger::log('updated', 'Shopee Order', null, 'Shipped order #' . $orderSn);

        if ($json) return response()->json(['ok' => true, 'message' => "Order {$orderSn} shipped successfully."]);

        return redirect()->route('ext.shopee.orders.index')->with('shopee_orders_last_result', [
            'ok' => true,
            'message' => "Order {$orderSn} shipped successfully.",
        ])->with('shopee_awb_open', $orderSn);
    }

    public function getTrackingNumber(ShopeeClient $client, string $orderSn)
    {
        $setting = ShopeeSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->partner_id || !$setting->partner_key || !$setting->access_token || !$setting->shop_id) {
            return response()->json(['ok' => false, 'message' => 'Missing Shopee credentials.'], 422);
        }

        $res = $client->shopGet(
            $setting->mode ?? 'live',
            (int) $setting->partner_id,
            (string) $setting->partner_key,
            (string) $setting->access_token,
            (int) $setting->shop_id,
            '/api/v2/logistics/get_tracking_number',
            ['order_sn' => $orderSn]
        );

        ShopeeApiLog::safeCreate([
            'pack'            => 'shopee.logistics.get_tracking_number',
            'method'          => 'GET',
            'api_path'        => '/api/v2/logistics/get_tracking_number',
            'auth_required'   => true,
            'request_params'  => ['order_sn' => $orderSn],
            'response_status' => (int) ($res['status'] ?? 0),
            'ok'              => (bool) ($res['ok'] ?? false),
            'response_body'   => $res['body'] ?? null,
        ]);

        $body = $res['body'] ?? [];
        $respData = $body['response'] ?? $body;

        return response()->json([
            'ok'              => (bool) ($res['ok'] ?? false),
            'tracking_number' => $respData['tracking_number'] ?? null,
            'body'            => $respData,
        ]);
    }

    public function getTrackingInfo(ShopeeClient $client, string $orderSn)
    {
        $setting = ShopeeSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->partner_id || !$setting->partner_key || !$setting->access_token || !$setting->shop_id) {
            return response()->json(['ok' => false, 'message' => 'Missing Shopee credentials.'], 422);
        }

        $res = $client->shopGet(
            $setting->mode ?? 'live',
            (int) $setting->partner_id,
            (string) $setting->partner_key,
            (string) $setting->access_token,
            (int) $setting->shop_id,
            '/api/v2/logistics/get_tracking_info',
            ['order_sn' => $orderSn]
        );

        ShopeeApiLog::safeCreate([
            'pack'            => 'shopee.logistics.get_tracking_info',
            'method'          => 'GET',
            'api_path'        => '/api/v2/logistics/get_tracking_info',
            'auth_required'   => true,
            'request_params'  => ['order_sn' => $orderSn],
            'response_status' => (int) ($res['status'] ?? 0),
            'ok'              => (bool) ($res['ok'] ?? false),
            'response_body'   => $res['body'] ?? null,
        ]);

        $body = $res['body'] ?? [];
        $respData = $body['response'] ?? $body;

        // Also fetch tracking number
        $trackRes = $client->shopGet(
            $setting->mode ?? 'live',
            (int) $setting->partner_id,
            (string) $setting->partner_key,
            (string) $setting->access_token,
            (int) $setting->shop_id,
            '/api/v2/logistics/get_tracking_number',
            ['order_sn' => $orderSn]
        );
        $trackBody = $trackRes['body'] ?? [];
        $trackResp = $trackBody['response'] ?? $trackBody;
        $trackingNo = trim((string) ($trackResp['tracking_number'] ?? ''));

        // Get shipping carrier from order raw
        $order = \Extensions\shopee\Models\ShopeeOrder::where('order_sn', $orderSn)->first();
        $raw = is_array($order->raw ?? null) ? $order->raw : [];
        $carrier = (string) ($raw['shipping_carrier'] ?? '');

        return response()->json([
            'ok'              => (bool) ($res['ok'] ?? false),
            'tracking_number' => $trackingNo,
            'shipping_carrier' => $carrier,
            'tracking_info'   => $respData['tracking_info'] ?? [],
            'logistics_status' => $respData['logistics_status'] ?? null,
        ]);
    }

    public function awbPdf(Request $request, ShopeeClient $client, string $orderSn)
    {
        $json = $request->expectsJson();

        // Serve saved AWB if it exists locally
        $safeOrderSn = preg_replace('/[^A-Za-z0-9_-]/', '', $orderSn);
        $awbDir = storage_path('app/shopee-awb');
        $awbPath = $awbDir . '/' . $safeOrderSn . '.pdf';

        if (file_exists($awbPath)) {
            if ($json) return response()->json(['ok' => true, 'ready' => true]);
            return response(file_get_contents($awbPath), 200)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'inline; filename="AWB-' . $safeOrderSn . '.pdf"');
        }

        $setting = ShopeeSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->partner_id || !$setting->partner_key || !$setting->access_token || !$setting->shop_id) {
            if ($json) return response()->json(['ok' => false, 'ready' => false, 'message' => 'Missing Shopee credentials/token.'], 422);
            return redirect()->route('ext.shopee.orders.index')->with('shopee_orders_last_result', [
                'ok' => false,
                'message' => 'Missing Shopee credentials/token.',
            ]);
        }

        $mode = $setting->mode ?? 'live';
        $pid = (int) $setting->partner_id;
        $pkey = (string) $setting->partner_key;
        $token = (string) $setting->access_token;
        $shopId = (int) $setting->shop_id;

        // Fetch tracking number from Shopee — needed for create_shipping_document
        $trackRes = $client->shopGet($mode, $pid, $pkey, $token, $shopId,
            '/api/v2/logistics/get_tracking_number',
            ['order_sn' => $orderSn]
        );
        $trackBody = $trackRes['body'] ?? [];
        $trackResp = is_array($trackBody) ? ($trackBody['response'] ?? []) : [];
        $trackingNo = trim((string) ($trackResp['tracking_number'] ?? ''));

        // Step 1: Get shipping document parameter to find supported document type
        $paramRes = $client->shopPost($mode, $pid, $pkey, $token, $shopId,
            '/api/v2/logistics/get_shipping_document_parameter', [],
            ['order_list' => [['order_sn' => $orderSn]]]
        );

        $paramBody = $paramRes['body'] ?? [];
        $paramResp = is_array($paramBody) ? ($paramBody['response'] ?? $paramBody) : [];
        $paramList = $paramResp['result_list'] ?? [];
        $paramFirst = $paramList[0] ?? [];
        $docType = $paramFirst['suggest_shipping_document_type'] ?? 'THERMAL_AIR_WAYBILL';
        $packageNumber = $paramFirst['package_number'] ?? null;

        ShopeeApiLog::safeCreate([
            'pack'            => 'shopee.logistics.get_shipping_document_parameter',
            'method'          => 'POST',
            'api_path'        => '/api/v2/logistics/get_shipping_document_parameter',
            'auth_required'   => true,
            'request_params'  => ['order_sn' => $orderSn],
            'response_status' => (int) ($paramRes['status'] ?? 0),
            'ok'              => (bool) ($paramRes['ok'] ?? false),
            'response_body'   => is_array($paramBody) ? $paramBody : null,
        ]);

        // Build order entry with package_number and tracking_number
        $orderEntry = ['order_sn' => $orderSn, 'shipping_document_type' => $docType];
        if ($packageNumber) {
            $orderEntry['package_number'] = $packageNumber;
        }
        if ($trackingNo !== '') {
            $orderEntry['tracking_number'] = $trackingNo;
        }

        // Step 2: Create shipping document (don't abort on failure — proceed to poll & download)
        $createRes = $client->shopPost($mode, $pid, $pkey, $token, $shopId,
            '/api/v2/logistics/create_shipping_document', [],
            ['order_list' => [$orderEntry]]
        );

        $createBody = $createRes['body'] ?? [];
        $createError = is_array($createBody) ? ($createBody['error'] ?? '') : '';

        ShopeeApiLog::safeCreate([
            'pack'            => 'shopee.logistics.create_shipping_document',
            'method'          => 'POST',
            'api_path'        => '/api/v2/logistics/create_shipping_document',
            'auth_required'   => true,
            'request_params'  => $orderEntry,
            'response_status' => (int) ($createRes['status'] ?? 0),
            'ok'              => (bool) ($createRes['ok'] ?? false),
            'response_body'   => is_array($createBody) ? $createBody : null,
        ]);

        // For AJAX: fast path — if create failed, return immediately so frontend retries
        if ($json && $createError !== '' && $createError !== 'success') {
            $failMsg = (($createBody['response']['result_list'] ?? [])[0]['fail_message'] ?? null)
                ?: ($createBody['message'] ?? 'Document not ready yet');
            return response()->json(['ok' => false, 'ready' => false, 'message' => $failMsg], 422);
        }

        // Step 3: Poll get_shipping_document_result until READY
        $ready = false;
        $lastFailMsg = null;
        $maxPolls = $json ? 2 : 5;        // AJAX: quick check; browser: full wait
        $pollSleep = $json ? 1 : 2;       // AJAX: 1s; browser: 2s
        for ($attempt = 0; $attempt < $maxPolls; $attempt++) {
            sleep($pollSleep);

            $pollEntry = ['order_sn' => $orderSn];
            if ($packageNumber) {
                $pollEntry['package_number'] = $packageNumber;
            }
            $resultRes = $client->shopPost($mode, $pid, $pkey, $token, $shopId,
                '/api/v2/logistics/get_shipping_document_result', [],
                ['order_list' => [$pollEntry]]
            );

            $pollBody = $resultRes['body'] ?? [];
            $pollResp = is_array($pollBody) ? ($pollBody['response'] ?? $pollBody) : [];
            $pollList = $pollResp['result_list'] ?? [];
            $pollFirst = $pollList[0] ?? [];
            $status = $pollFirst['status'] ?? '';

            if ($status === 'READY') {
                $ready = true;
                break;
            }

            if ($status === 'FAILED') {
                $lastFailMsg = $pollFirst['fail_message'] ?? ($pollFirst['fail_error'] ?? 'Document creation failed');
                break;
            }

            $pollError = is_array($pollBody) ? ($pollBody['error'] ?? '') : '';
            if ($pollError !== '' && $pollError !== 'success') {
                break;
            }
        }

        // Step 4: Download shipping document (attempt even if polling didn't confirm READY)
        $dlEntry = ['order_sn' => $orderSn];
        if ($packageNumber) {
            $dlEntry['package_number'] = $packageNumber;
        }
        $downloadRes = $client->shopPost($mode, $pid, $pkey, $token, $shopId,
            '/api/v2/logistics/download_shipping_document', [],
            ['order_list' => [$dlEntry], 'shipping_document_type' => $docType]
        );

        $body = $downloadRes['body'] ?? null;

        // Binary PDF returned directly — save to disk then serve
        if (is_string($body) && str_starts_with($body, '%PDF')) {
            if (!is_dir($awbDir)) {
                mkdir($awbDir, 0755, true);
            }
            file_put_contents($awbPath, $body);

            if ($json) return response()->json(['ok' => true, 'ready' => true]);
            return response($body, 200)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'inline; filename="AWB-' . $safeOrderSn . '.pdf"');
        }

        // JSON error response — download failed
        $dlErrMsg = 'Unknown error';
        if (is_array($body)) {
            $dlErrMsg = $body['message'] ?? ($body['error'] ?? $dlErrMsg);
            $respData = $body['response'] ?? $body;
            if (is_array($respData) && isset($respData['result_list'])) {
                $firstDl = $respData['result_list'][0] ?? [];
                if (!empty($firstDl['fail_message'])) {
                    $dlErrMsg = $firstDl['fail_message'];
                } elseif (!empty($firstDl['fail_error'])) {
                    $dlErrMsg = $firstDl['fail_error'];
                }
            }
        }

        ShopeeApiLog::safeCreate([
            'pack'            => 'shopee.logistics.download_shipping_document',
            'method'          => 'POST',
            'api_path'        => '/api/v2/logistics/download_shipping_document',
            'auth_required'   => true,
            'request_params'  => ['order_sn' => $orderSn],
            'response_status' => (int) ($downloadRes['status'] ?? 0),
            'ok'              => false,
            'response_body'   => is_array($body) ? $body : ['raw_length' => is_string($body) ? strlen($body) : 0],
        ]);

        // Build informative error: show create + poll + download context
        $createError = is_array($createBody) ? ($createBody['error'] ?? '') : '';
        $errorMsg = 'Failed to download AWB: ' . $dlErrMsg;
        if ($createError !== '' && $createError !== 'success') {
            $createResp = is_array($createBody) ? ($createBody['response'] ?? $createBody) : [];
            $createList = is_array($createResp) ? ($createResp['result_list'] ?? []) : [];
            $createFirst = $createList[0] ?? [];
            $createFailMsg = $createFirst['fail_message'] ?? ($createBody['message'] ?? $createError);
            $errorMsg = 'AWB create: ' . $createFailMsg;
            if ($lastFailMsg) {
                $errorMsg .= ' | Poll: ' . $lastFailMsg;
            }
            $errorMsg .= ' | Download: ' . $dlErrMsg;
        }

        if ($json) return response()->json(['ok' => false, 'ready' => false, 'message' => $errorMsg], 422);

        return redirect()->route('ext.shopee.orders.index')->with('shopee_orders_last_result', [
            'ok' => false,
            'message' => $errorMsg,
        ]);
    }

    public function reset(Request $request)
    {
        $request->validate([
            'confirm' => 'required|in:RESET',
            'password' => 'required|string',
        ]);

        if (!Hash::check($request->input('password'), auth()->user()->password)) {
            return redirect()->route('ext.shopee.orders.index')->with('shopee_orders_last_result', [
                'ok' => false,
                'message' => 'Incorrect password. Reset cancelled.',
            ]);
        }

        $setting = ShopeeSetting::query()->first();
        $region = $setting->region ?? 'ph';

        try {
            $count = ShopeeOrder::query()->where('region', $region)->count();

            \DB::transaction(function () use ($region) {
                ShopeeOrder::query()->where('region', $region)->delete();
            });

            ActivityLogger::log('deleted', 'Shopee Order', null, 'Reset ' . $count . ' Shopee order(s)');

            return redirect()->route('ext.shopee.orders.index')->with('shopee_orders_last_result', [
                'ok' => true,
                'message' => 'Shopee orders have been reset. Please click Fetch Orders to re-sync.',
            ]);
        } catch (\Throwable $e) {
            return redirect()->route('ext.shopee.orders.index')->with('shopee_orders_last_result', [
                'ok' => false,
                'message' => 'Failed to reset Shopee orders.',
            ]);
        }
    }

    public function fetchReturns(Request $request, ShopeeClient $client)
    {
        try { @set_time_limit(0); } catch (\Throwable $e) {}

        $setting = ShopeeSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->partner_id || !$setting->partner_key || !$setting->access_token || !$setting->shop_id) {
            return redirect()->route('ext.shopee.orders.index', ['tab' => 'RETURN'])->with('shopee_orders_last_result', [
                'ok' => false,
                'message' => 'Missing Shopee credentials/token. Please configure Shopee settings first.',
            ]);
        }

        $region = $setting->region ?? 'ph';
        $saved = 0;
        $errors = 0;
        $pageNo = 0;
        $pageSize = 50;

        while (true) {
            $res = $client->shopGet(
                $setting->mode ?? 'live',
                (int) $setting->partner_id,
                (string) $setting->partner_key,
                (string) $setting->access_token,
                (int) $setting->shop_id,
                '/api/v2/returns/get_return_list',
                ['page_no' => $pageNo, 'page_size' => $pageSize]
            );

            ShopeeApiLog::safeCreate([
                'pack'            => 'shopee.returns.get_return_list',
                'method'          => 'GET',
                'api_path'        => '/api/v2/returns/get_return_list',
                'auth_required'   => true,
                'request_params'  => ['page_no' => $pageNo, 'page_size' => $pageSize],
                'response_status' => (int) ($res['status'] ?? 0),
                'ok'              => (bool) ($res['ok'] ?? false),
                'response_body'   => $res['body'] ?? null,
            ]);

            if (!($res['ok'] ?? false)) {
                $body = $res['body'] ?? [];
                $msg = is_array($body) ? ($body['message'] ?? ($body['msg'] ?? 'API error')) : 'API error';
                return redirect()->route('ext.shopee.orders.index', ['tab' => 'RETURN'])->with('shopee_orders_last_result', [
                    'ok' => false,
                    'message' => 'Shopee Returns API error: ' . $msg,
                ]);
            }

            $body = $res['body'] ?? [];
            $respData = $body['response'] ?? $body;
            $returnList = $respData['return_list'] ?? [];
            if (!is_array($returnList) || empty($returnList)) {
                break;
            }

            foreach ($returnList as $ret) {
                $returnSn = $ret['return_sn'] ?? null;
                if (!$returnSn) continue;

                // Fetch detail for this return
                $detailRes = $client->shopGet(
                    $setting->mode ?? 'live',
                    (int) $setting->partner_id,
                    (string) $setting->partner_key,
                    (string) $setting->access_token,
                    (int) $setting->shop_id,
                    '/api/v2/returns/get_return_detail',
                    ['return_sn' => $returnSn]
                );

                ShopeeApiLog::safeCreate([
                    'pack'            => 'shopee.returns.get_return_detail',
                    'method'          => 'GET',
                    'api_path'        => '/api/v2/returns/get_return_detail',
                    'auth_required'   => true,
                    'request_params'  => ['return_sn' => $returnSn],
                    'response_status' => (int) ($detailRes['status'] ?? 0),
                    'ok'              => (bool) ($detailRes['ok'] ?? false),
                    'response_body'   => $detailRes['body'] ?? null,
                ]);

                if (!($detailRes['ok'] ?? false)) {
                    $errors++;
                    continue;
                }

                $detailBody = $detailRes['body'] ?? [];
                $detail = $detailBody['response'] ?? $detailBody;

                $orderSn = (string) ($detail['order_sn'] ?? ($ret['order_sn'] ?? ''));

                // Find linked shopee_order
                $shopeeOrder = null;
                if ($orderSn !== '') {
                    $shopeeOrder = ShopeeOrder::query()
                        ->where('region', $region)
                        ->where('order_sn', $orderSn)
                        ->first();
                }

                try {
                    ShopeeReturn::query()->updateOrCreate(
                        [
                            'region'    => $region,
                            'return_sn' => $returnSn,
                        ],
                        [
                            'order_sn'          => $orderSn,
                            'shopee_order_id'   => $shopeeOrder?->id,
                            'status'            => (string) ($detail['status'] ?? ($ret['status'] ?? '')),
                            'reason'            => (string) ($detail['reason'] ?? ($ret['reason'] ?? '')),
                            'reason_text'       => (string) ($detail['text_reason'] ?? ($detail['reason_text'] ?? '')),
                            'refund_amount'     => (float) ($detail['refund_amount'] ?? ($ret['refund_amount'] ?? 0)),
                            'currency'          => (string) ($detail['currency'] ?? ''),
                            'items'             => $detail['item'] ?? ($detail['items'] ?? null),
                            'negotiation'       => $detail['negotiation'] ?? null,
                            'raw'               => $detail,
                            'return_created_at' => $this->parseTimestamp($detail['create_time'] ?? null),
                            'return_updated_at' => $this->parseTimestamp($detail['update_time'] ?? null),
                        ]
                    );
                    $saved++;
                } catch (\Throwable $e) {
                    $errors++;
                }
            }

            $more = (bool) ($respData['more'] ?? false);
            if (!$more) {
                break;
            }

            $pageNo++;
            if ($pageNo > 50) break; // safety limit
        }

        $message = $saved > 0
            ? "Synced {$saved} return(s)." . ($errors > 0 ? " {$errors} error(s)." : '')
            : 'No returns found.' . ($errors > 0 ? " {$errors} error(s)." : '');

        return redirect()->route('ext.shopee.orders.index', ['tab' => 'RETURN'])->with('shopee_orders_last_result', [
            'ok' => true,
            'message' => $message,
        ]);
    }

    // --- Private helpers ---

    private function syncOrderProductsFromDetail(ShopeeOrder $order, array $detail): void
    {
        $itemList = $detail['item_list'] ?? [];
        if (!is_array($itemList)) return;

        foreach ($itemList as $it) {
            if (!is_array($it)) continue;

            $itemId = (string) ($it['item_id'] ?? '');
            $modelId = (string) ($it['model_id'] ?? '');

            $sku = (string) ($it['item_sku'] ?? ($it['model_sku'] ?? ''));
            $name = (string) ($it['item_name'] ?? ($it['item_name'] ?? ''));
            $variation = (string) ($it['model_name'] ?? '');
            $qty = max(1, (int) ($it['model_quantity_purchased'] ?? ($it['quantity'] ?? 1)));
            $price = (float) ($it['model_discounted_price'] ?? ($it['model_original_price'] ?? 0));
            $image = (string) ($it['image_info']['image_url'] ?? '');

            ShopeeOrderProduct::query()->updateOrCreate(
                [
                    'shopee_order_id' => $order->id,
                    'item_id'         => $itemId !== '' ? $itemId : null,
                    'model_id'        => $modelId !== '' ? $modelId : null,
                ],
                [
                    'sku'       => $sku !== '' ? $sku : null,
                    'name'      => $name !== '' ? $name : null,
                    'variation' => $variation !== '' ? $variation : null,
                    'quantity'  => $qty,
                    'price'     => $price,
                    'image'     => $image !== '' ? $image : null,
                    'raw'       => $it,
                ]
            );
        }
    }

    private function parseTimestamp($value): ?string
    {
        if ($value === null) return null;

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $num = (int) $value;
            if ($num > 0) return date('Y-m-d H:i:s', $num);
            return null;
        }

        $str = trim((string) $value);
        if ($str === '') return null;

        $ts = strtotime($str);
        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }

}
