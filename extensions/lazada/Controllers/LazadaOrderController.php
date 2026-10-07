<?php

namespace Extensions\lazada\Controllers;

use App\Http\Controllers\Controller;

use Extensions\lazada\Models\LazadaSetting;
use Extensions\lazada\Models\LazadaApiLog;
use Extensions\lazada\Models\LazadaOrder;
use Extensions\lazada\Models\LazadaOrderProduct;
use Extensions\lazada\Models\LazadaReverseOrder;
use App\Services\ActivityLogger;
use Extensions\lazada\Services\Lazada\LazadaClient;
use Extensions\lazada\Services\LazadaCatalogOrderSync;
use Extensions\lazada\Services\LazadaOrdersPanel;
use Extensions\lazada\Services\LazadaReturnsPanel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class LazadaOrderController extends Controller
{
    use \App\Http\Controllers\Concerns\DrivesOrderFetchRuns;

    public function index(Request $request, LazadaOrdersPanel $panel)
    {
        return view('ext-lazada::orders.index', $panel->build($request));
    }

    public function returns(Request $request, LazadaReturnsPanel $panel)
    {
        return view('ext-lazada::orders.returns', $panel->build($request));
    }

    public function fetchReturns(Request $request, LazadaClient $client)
    {
        try { @set_time_limit(0); } catch (\Throwable $e) {}

        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
            return redirect()->route('ext.lazada.orders.returns')->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'Missing Lazada credentials/token. Please configure Lazada settings first.',
            ]);
        }

        $pageSize = 50;
        $allOrders = [];
        $res = null;

        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        for ($pageNo = 1; $pageNo <= 20; $pageNo++) {
            $apiParams = ['pageNo' => $pageNo, 'pageSize' => $pageSize];
            if ($dateFrom) {
                $apiParams['create_time_start'] = $dateFrom . ' 00:00:00';
            }
            if ($dateTo) {
                $apiParams['create_time_end'] = $dateTo . ' 23:59:59';
            }

            $res = $this->runSignedApiCall(
                $client,
                $setting->region,
                $creds['app_key'],
                $creds['app_secret'],
                $creds['access_token'],
                'GET',
                '/reverse/getreverseordersforseller',
                true,
                $apiParams,
                'lazada.reverse.list'
            );

            $body = $res['body'] ?? [];
            $pageOrders = [];
            if (($res['ok'] ?? false) && is_array($body)) {
                $dataNode = $body['data'] ?? $body;
                $pageOrders = $dataNode['list'] ?? $dataNode['reverse_order_list'] ?? $dataNode['data'] ?? [];
                if (!is_array($pageOrders)) $pageOrders = [];
            }

            if (!($res['ok'] ?? false)) {
                break;
            }

            $allOrders = array_merge($allOrders, $pageOrders);

            if (count($pageOrders) < $pageSize) {
                break;
            }
        }

        if (!($res['ok'] ?? false) && empty($allOrders)) {
            $msg = 'Failed to fetch reverse orders.';
            $body = $res['body'] ?? [];
            if (is_array($body)) {
                $msg = (string)($body['message'] ?? $body['msg'] ?? $msg);
            }
            return redirect()->route('ext.lazada.orders.returns')->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => $msg,
            ]);
        }

        $saved = 0;
        $updated = 0;

        foreach ($allOrders as $o) {
            if (!is_array($o)) continue;

            $reverseOrderId = (string)($o['reverse_order_id'] ?? $o['reverseOrderId'] ?? '');
            if ($reverseOrderId === '') continue;

            $tradeOrderId = (string)($o['trade_order_id'] ?? $o['tradeOrderId'] ?? '');
            $reverseStatus = (string)($o['reverse_status'] ?? $o['reverseStatus'] ?? $o['status'] ?? '');
            $reverseType = (string)($o['reverse_type'] ?? $o['reverseType'] ?? $o['type'] ?? '');
            $reason = (string)($o['reason'] ?? $o['reverse_reason'] ?? '');
            $refundAmount = $o['refund_amount'] ?? $o['refundAmount'] ?? $o['actual_refund_amount'] ?? null;
            $currency = (string)($o['currency'] ?? '');
            $items = $o['items'] ?? $o['reverse_order_items'] ?? $o['reverseOrderItems'] ?? null;

            $openedStamps = [];
            foreach ((array) ($o['reverse_order_lines'] ?? []) as $reverseLine) {
                $lineStamp = $reverseLine['return_order_line_gmt_create'] ?? null;
                if (is_numeric($lineStamp) && (int) $lineStamp > 0) {
                    $openedStamps[] = (int) $lineStamp;
                }
            }
            $returnCreatedAt = $openedStamps === []
                ? null
                : \Illuminate\Support\Carbon::createFromTimestamp(min($openedStamps));

            $payload = [
                'region' => $setting->region,
                'reverse_order_id' => $reverseOrderId,
                'trade_order_id' => $tradeOrderId !== '' ? $tradeOrderId : null,
                'reverse_status' => $reverseStatus !== '' ? $reverseStatus : null,
                'reverse_type' => $reverseType !== '' ? $reverseType : null,
                'reason' => $reason !== '' ? $reason : null,
                'refund_amount' => is_numeric($refundAmount) ? $refundAmount : null,
                'currency' => $currency !== '' ? $currency : null,
                'return_created_at' => $returnCreatedAt,
                'items' => is_array($items) ? $items : null,
                'raw' => $o,
            ];

            $existing = LazadaReverseOrder::query()
                ->where('region', $setting->region)
                ->where('reverse_order_id', $reverseOrderId)
                ->first();

            if ($existing) {
                $existing->fill($payload)->save();
                $updated++;
            } else {
                LazadaReverseOrder::query()->create($payload);
                $saved++;
            }
        }

        $message = 'Reverse orders synced. ' . $saved . ' new, ' . $updated . ' updated.';

        return redirect()->route('ext.lazada.orders.returns')->with('lazada_orders_last_result', [
            'ok' => true,
            'message' => $message,
        ]);
    }

    public function show(Request $request, LazadaClient $client, string $orderId)
    {
        $orderId = trim($orderId);
        if ($orderId === '' || mb_strlen($orderId) > 80) {
            abort(404);
        }

        $settingRaw = LazadaSetting::defaultStore();
        $region = $settingRaw->region ?? 'ph';

        $order = LazadaOrder::query()
            ->where('region', $region)
            ->where('order_id', $orderId)
            ->with('products')
            ->firstOrFail();

        $apiOrder = null;
        $apiItemsSynced = false;
        $apiError = null;

        $refresh = (bool) $request->boolean('refresh');
        $needsDetail = $refresh || !$this->rawLikelyHasOrderDetail($order->raw ?? []);

        $setting = $settingRaw?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        $hasCreds = $setting && $setting->region && $creds['app_key'] && $creds['app_secret'] && $creds['access_token'];

        $canManage = (bool) $request->user()?->hasPermission('manage_lazada/order');

        if ($hasCreds && $canManage && $needsDetail) {
            try {
                $cacheKey = 'lazada.order.detail.' . $setting->region . '.' . $orderId;
                $ttlSeconds = 300;
                if ($refresh) {
                    Cache::forget($cacheKey);
                }

                $apiOrder = Cache::remember($cacheKey, $ttlSeconds, function () use ($client, $setting, $orderId, $creds) {
                    $res = $this->runSignedApiCall(
                        $client,
                        $setting->region,
                        $creds['app_key'],
                        $creds['app_secret'],
                        $creds['access_token'],
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

        if ($hasCreds && $canManage && ($refresh || !$order->products()->limit(1)->exists())) {
            try {
                $synced = $this->syncOrderProductsFromApi($client, $setting, $order);
                if ($synced !== null) {
                    $apiItemsSynced = true;
                    $order->load('products');
                }
            } catch (\Throwable $e) {
            }
        }

        if ($hasCreds && $canManage && (empty($order->fees) || $refresh) && $order->products()->exists()) {
            try {
                $fees = $this->extractLazadaFees($order);

                $trxRes = $this->runSignedApiCall(
                    $client,
                    $setting->region,
                    $creds['app_key'],
                    $creds['app_secret'],
                    $creds['access_token'],
                    'GET',
                    '/finance/transaction/details/get',
                    true,
                    ['trade_order_id' => (string) $order->order_id, 'start_time' => now()->subDays(170)->format('Y-m-d'), 'end_time' => now()->addDay()->format('Y-m-d')],
                    'lazada.finance.transaction_details'
                );

                if (($trxRes['ok'] ?? false) && is_array($trxRes['body'] ?? null)) {
                    $trxData = $trxRes['body']['data'] ?? $trxRes['body'];
                    $fees['_finance_raw'] = $trxData;

                    $trxItems = [];
                    if (is_array($trxData)) {
                        $trxItems = array_is_list($trxData) ? $trxData : ($trxData['data'] ?? $trxData['items'] ?? $trxData['transaction_details'] ?? []);
                    }

                    $commissionTypes = [16, 15, 65, 66, 123, 274, 275, 277, 278, 341];
                    $paymentTypes = [3, 4, 67, 84, 514];
                    $shippingTypes = [7, 8, 21, 26, 27, 28, 34, 35, 42, 43, 49, 52, 53, 141, 157, 158, 159, 160, 161, 200, 211, 500, 501, 502, 503, 504, 505];

                    if (is_array($trxItems)) {
                        $commissionTotal = 0;
                        $paymentFeeTotal = 0;
                        $shippingFeeTotal = 0;
                        $otherFees = [];
                        $transactionLines = [];

                        foreach ($trxItems as $trx) {
                            if (!is_array($trx)) continue;

                            $feeType = (int) ($trx['fee_type'] ?? 0);
                            $feeName = (string) ($trx['fee_name'] ?? $trx['transaction_type'] ?? '');
                            // Money::parse strips the thousands comma Lazada sends ("-2,251.57"); a bare float cast reads -2.
                            $amount = \App\Support\Money::parse($trx['amount'] ?? $trx['fee_amount'] ?? 0);
                            $trxDate = (string) ($trx['transaction_date'] ?? $trx['paid_time'] ?? '');
                            $trxNumber = (string) ($trx['transaction_number'] ?? '');
                            $orderItemId = (string) ($trx['order_item_id'] ?? $trx['orderItemId'] ?? '');
                            $sku = (string) ($trx['seller_sku'] ?? $trx['sku'] ?? '');

                            if ($feeType && in_array($feeType, $commissionTypes)) {
                                $commissionTotal += $amount;
                            } elseif ($feeType && in_array($feeType, $paymentTypes)) {
                                $paymentFeeTotal += $amount;
                            } elseif ($feeType && in_array($feeType, $shippingTypes)) {
                                $shippingFeeTotal += $amount;
                            } elseif (!$feeType && $feeName !== '') {
                                $feeNameLower = strtolower($feeName);
                                if (str_contains($feeNameLower, 'commission')) {
                                    $commissionTotal += $amount;
                                } elseif (str_contains($feeNameLower, 'payment')) {
                                    $paymentFeeTotal += $amount;
                                } elseif (str_contains($feeNameLower, 'shipping') || str_contains($feeNameLower, 'delivery')) {
                                    $shippingFeeTotal += $amount;
                                } else {
                                    $label = $feeName !== '' ? $feeName : 'fee_type_' . $feeType;
                                    $otherFees[$label] = ($otherFees[$label] ?? 0) + $amount;
                                }
                            } else {
                                $label = $feeName !== '' ? $feeName : 'fee_type_' . $feeType;
                                $otherFees[$label] = ($otherFees[$label] ?? 0) + $amount;
                            }

                            if ($amount != 0) {
                                $transactionLines[] = [
                                    'fee_type' => $feeType,
                                    'fee_name' => $feeName,
                                    'amount' => round($amount, 2),
                                    'sku' => $sku,
                                    'order_item_id' => $orderItemId,
                                    'transaction_number' => $trxNumber,
                                    'transaction_date' => $trxDate,
                                ];
                            }
                        }

                        if ($commissionTotal != 0) $fees['commission'] = round($commissionTotal, 2);
                        if ($paymentFeeTotal != 0) $fees['payment_fee'] = round($paymentFeeTotal, 2);
                        if ($shippingFeeTotal != 0) $fees['shipping_service_cost'] = round($shippingFeeTotal, 2);
                        if (!empty($otherFees)) $fees['other_fees'] = $otherFees;
                        if (!empty($transactionLines)) $fees['transaction_lines'] = $transactionLines;
                    }
                }

                $order->fees = $fees;
                $order->save();
            } catch (\Throwable $e) {
            }
        }

        $raw = is_array($order->raw) ? $order->raw : [];
        $detail = (isset($raw['_detail']) && is_array($raw['_detail'])) ? $raw['_detail'] : [];

        return view('ext-lazada::orders.show', [
            'order' => $order,
            'breakdown' => \Extensions\lazada\Services\LazadaOrderBreakdown::from(is_array($order->fees) ? $order->fees : []),
            'breakdownCurrency' => (string) ((is_array($order->raw) ? ($order->raw['currency'] ?? null) : null) ?: \App\Support\Money::defaultCode()),
            'setting_region' => $region,
            'detail' => $detail,
            'api_items_synced' => $apiItemsSynced,
            'api_error' => $apiError,
        ]);
    }

    private function extractLazadaFees(LazadaOrder $order): array
    {
        $items = $order->products()->get();
        $subtotal = 0;
        $paidTotal = 0;
        $shippingTotal = 0;
        $voucherSeller = 0;
        $voucherPlatform = 0;
        $shippingDiscountSeller = 0;
        $shippingDiscountPlatform = 0;
        $walletCredits = 0;
        $shippingServiceCost = 0;

        $m = fn ($v) => \App\Support\Money::parse($v);
        foreach ($items as $item) {
            $ir = is_array($item->raw) ? $item->raw : [];
            $qty = max(1, (int) ($item->quantity ?? 1));

            $itemPrice = $m($ir['item_price'] ?? ($ir['price'] ?? 0));
            $paidPrice = $m($ir['paid_price'] ?? $itemPrice);
            $subtotal += $itemPrice * $qty;
            $paidTotal += $paidPrice;
            $shippingTotal += $m($ir['shipping_amount'] ?? ($ir['shipping_fee_original'] ?? 0));
            $voucherSeller += $m($ir['voucher_seller'] ?? 0);
            $voucherPlatform += $m($ir['voucher_platform'] ?? 0);
            $shippingDiscountSeller += $m($ir['shipping_fee_discount_seller'] ?? 0);
            $shippingDiscountPlatform += $m($ir['shipping_fee_discount_platform'] ?? 0);
            $walletCredits += $m($ir['wallet_credits'] ?? 0);
            $shippingServiceCost += $m($ir['shipping_service_cost'] ?? 0);
        }

        $raw = is_array($order->raw) ? $order->raw : [];
        $detail = $raw['_detail'] ?? $raw;
        $orderPrice = $m($detail['price'] ?? 0);
        $orderShipping = $m($detail['shipping_fee'] ?? ($detail['shipping_amount'] ?? 0));
        $orderVoucher = $m($detail['voucher'] ?? ($detail['voucher_amount'] ?? 0));

        return [
            'subtotal' => round($subtotal, 2),
            'paid_total' => round($paidTotal, 2),
            'shipping' => round($shippingTotal ?: $orderShipping, 2),
            'voucher_seller' => round($voucherSeller, 2),
            'voucher_platform' => round($voucherPlatform, 2),
            'shipping_discount_seller' => round($shippingDiscountSeller, 2),
            'shipping_discount_platform' => round($shippingDiscountPlatform, 2),
            'wallet_credits' => round($walletCredits, 2),
            'shipping_service_cost' => round($shippingServiceCost, 2),
            'order_price' => round($orderPrice, 2),
            'order_voucher' => round($orderVoucher, 2),
        ];
    }

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

        foreach (['items_count', 'voucher', 'payment_method', 'invoice_number'] as $k) {
            if (array_key_exists($k, $raw)) {
                return true;
            }
        }

        return false;
    }

    public function packAndPrint(Request $request, LazadaClient $client, $orderId)
    {
        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
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

        if (count($items['package_ids']) > 0) {
            return redirect()->back()->with('lazada_orders_last_result', [
                'ok' => false,
                'error' => 'already_done',
                'message' => 'This order is already packed.',
            ]);
        }

        if (count($items['order_item_ids']) === 0) {
            return redirect()->back()->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'No order_item_ids found for this order.',
                'raw' => $items['raw'],
            ]);
        }

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
            $creds['app_key'],
            $creds['app_secret'],
            $creds['access_token'],
            'POST',
            '/order/fulfill/pack',
            true,
            [
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

        $this->afterBooking($client, $setting, (string) $orderId, 'Packed order #');

        return redirect()->back()->with('lazada_orders_last_result', [
            'ok' => true,
            'message' => 'Packed. Choose Print Only, Ship & Print, or Recreate Package.',
            'raw' => $packRes,
        ])->with('open_pack_print_modal_order_id', (string)$orderId);
    }

    public function bulkPackPrint(Request $request, LazadaClient $client)
    {
        try { @set_time_limit(0); } catch (\Throwable $e) {}

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:50'],
            'ids.*' => ['required', 'integer'],
        ], [
            'ids.max' => 'Pack at most 50 orders at a time. Select fewer and run it again.',
            'ids.required' => 'Pick the orders to pack first.',
        ]);

        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
            return redirect()->back()->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'Missing Lazada credentials/token. Please configure Lazada settings and generate access token first.',
            ]);
        }

        $orders = LazadaOrder::query()
            ->where('region', $setting->region)
            ->whereIn('id', $data['ids'])
            ->orderBy('id')
            ->get();

        if ($orders->isEmpty()) {
            return redirect()->back()->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'None of those orders are still here. Reload the list and pick again.',
            ]);
        }

        $outcomes = [];
        foreach ($orders as $order) {
            $outcomes[] = $this->packOneForBulk($client, $setting, $order);
        }

        $found = $orders->pluck('id')->all();
        foreach (array_diff($data['ids'], $found) as $missingId) {
            $outcomes[] = [
                'local_id' => (int) $missingId,
                'order_id' => '',
                'ok' => false,
                'state' => 'gone',
                'message' => 'No longer in this list. It may have been removed by a sync.',
            ];
        }

        $packed = array_values(array_filter($outcomes, fn ($o) => $o['ok']));

        ActivityLogger::log(
            'lazada.orders.bulk_pack',
            null,
            null,
            'Bulk packed ' . count($packed) . ' of ' . count($outcomes) . ' Lazada orders',
            ['outcomes' => $outcomes]
        );

        return redirect()->back()->with('lazada_bulk_pack', [
            'outcomes' => $outcomes,
            'packed_ids' => array_column($packed, 'local_id'),
            'packed' => count($packed),
            'total' => count($outcomes),
        ]);
    }

    private function packOneForBulk(LazadaClient $client, $setting, LazadaOrder $order): array
    {
        $orderId = (string) $order->order_id;

        $out = function (bool $ok, string $state, string $message) use ($order, $orderId) {
            return [
                'local_id' => (int) $order->id,
                'order_id' => $orderId,
                'ok' => $ok,
                'state' => $state,
                'message' => $message,
            ];
        };

        try {
            $items = $this->getOrderItems($client, $setting, $orderId);

            if (!($items['ok'] ?? false)) {
                return $out(false, 'failed', 'Could not read the order items.');
            }

            if ($items['is_sof'] ?? false) {
                return $out(false, 'sof', 'An SOF or DBS order. Lazada does not support packing it through this API.');
            }

            // An order that already has packages is skipped; packing again makes duplicate packages on Lazada.
            if (count($items['package_ids'] ?? []) > 0) {
                return $out(true, 'already', 'Already packed. Its waybill is included.');
            }

            $orderItemList = [];
            foreach (array_values($items['order_item_ids'] ?? []) as $oid) {
                $orderItemList[] = (string) $oid;
            }

            if ($orderItemList === []) {
                return $out(false, 'failed', 'Lazada returned no items for this order.');
            }

            $creds = LazadaSetting::activeCredentials($setting);

            $packRes = $this->runSignedApiCall(
                $client,
                $setting->region,
                $creds['app_key'],
                $creds['app_secret'],
                $creds['access_token'],
                'POST',
                '/order/fulfill/pack',
                true,
                [
                    'packReq' => json_encode([
                        'delivery_type' => 'dropship',
                        'shipping_allocate_type' => 'TFS',
                        'pack_order_list' => [[
                            'order_id' => $orderId,
                            'order_item_list' => $orderItemList,
                        ]],
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ],
                'lazada.order.fulfill.pack'
            );

            if (!($packRes['ok'] ?? false)) {
                return $out(false, 'failed', 'Lazada did not answer the pack request.');
            }

            $body = $packRes['body'] ?? [];
            if (is_array($body) && isset($body['code'], $body['message'])) {
                return $out(false, 'failed', 'Lazada error: ' . $body['code'] . ' - ' . $body['message']);
            }

            try {
                $st = $this->fetchOrderStatusFromApi($client, $setting, $orderId);
                if ($st !== null) {
                    LazadaOrder::query()
                        ->where('region', $setting->region)
                        ->where('order_id', $orderId)
                        ->update(['status' => $st]);
                }
                $fresh = LazadaOrder::query()
                    ->where('region', $setting->region)
                    ->where('order_id', $orderId)
                    ->first();
                if ($fresh) {
                    (new LazadaCatalogOrderSync)->sync($fresh);
                }
            } catch (\Throwable $e) {
            }

            return $out(true, 'packed', 'Packed.');
        } catch (\Throwable $e) {
            return $out(false, 'failed', 'Unexpected error: ' . $e->getMessage());
        }
    }

    public function bulkAwb(Request $request, LazadaClient $client)
    {
        try { @set_time_limit(0); } catch (\Throwable $e) {}

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:50'],
            'ids.*' => ['required', 'integer'],
        ]);

        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
            return $this->awbError('Missing Lazada credentials/token. Please configure Lazada settings and generate access token first.');
        }

        $orders = LazadaOrder::query()
            ->where('region', $setting->region)
            ->whereIn('id', $data['ids'])
            ->orderBy('id')
            ->get();

        $packages = [];
        $skipped = [];
        foreach ($orders as $order) {
            $items = $this->getOrderItems($client, $setting, (string) $order->order_id);
            if (!($items['ok'] ?? false) || ($items['is_sof'] ?? false)) {
                $skipped[] = (string) $order->order_id;
                continue;
            }
            foreach ($items['package_ids'] ?? [] as $pid) {
                $packages[] = ['package_id' => $pid];
            }
        }

        if ($packages === []) {
            return $this->awbError('None of those orders have a printable package yet. Pack them first.');
        }

        $awbRes = $this->runSignedApiCall(
            $client,
            $setting->region,
            $creds['app_key'],
            $creds['app_secret'],
            $creds['access_token'],
            'POST',
            '/order/package/document/get',
            true,
            [
                'getDocumentReq' => json_encode([
                    'doc_type' => 'PDF',
                    'packages' => $packages,
                ], JSON_UNESCAPED_SLASHES),
            ],
            'lazada.order.package.document.get'
        );

        if (!($awbRes['ok'] ?? false)) {
            return $this->awbError('Failed to retrieve the combined waybill PDF.');
        }

        $awbBody = $awbRes['body'] ?? [];
        if (is_array($awbBody) && isset($awbBody['code'], $awbBody['message'])) {
            return $this->awbError('Lazada error: ' . $awbBody['code'] . ' - ' . $awbBody['message']);
        }

        $dataNode = is_array($awbBody) ? ($awbBody['data'] ?? $awbBody) : $awbBody;
        if (is_array($dataNode) && isset($dataNode['code'], $dataNode['message'])) {
            return $this->awbError('Lazada error: ' . $dataNode['code'] . ' - ' . $dataNode['message']);
        }

        $doc = $this->findDocument($dataNode);
        if (!$doc) {
            return $this->awbError('The waybill response did not contain a document.');
        }

        if (($doc['type'] ?? '') === 'url') {
            $bin = $this->downloadPdf((string) ($doc['value'] ?? ''));
            if ($bin === null) {
                return $this->awbError(self::WAYBILL_UNREACHABLE);
            }
        } else {
            $bin = base64_decode((string) $doc['value'], true);
            if ($bin === false || $bin === '') {
                return $this->awbError('Invalid document returned from Lazada.');
            }
        }

        if (!str_starts_with($bin, '%PDF') && preg_match('/<iframe[^>]+src="([^"]+)"/i', $bin, $m)) {
            $bin = $this->downloadPdf((string) $m[1]);
            if ($bin === null) {
                return $this->awbError(self::WAYBILL_UNREACHABLE);
            }
        }

        if (!str_starts_with($bin, '%PDF')) {
            return $this->awbError('Lazada returned a document that is not a PDF.');
        }

        return response($bin, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="waybills_' . count($packages) . '.pdf"',
        ]);
    }

    public function packingList(Request $request, LazadaClient $client, $orderId)
    {
        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
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

        return view('ext-lazada::orders.packing_list', [
            'order' => $order,
            'orderId' => (string)$orderId,
            'items' => $items['items'],
        ]);
    }

    public function pickList(Request $request, LazadaClient $client, $orderId)
    {
        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
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

        $sorted = $items['items'];
        usort($sorted, function ($a, $b) {
            $as = strtolower((string)($a['sku'] ?? ''));
            $bs = strtolower((string)($b['sku'] ?? ''));
            if ($as === $bs) {
                return strcmp(strtolower((string)($a['name'] ?? '')), strtolower((string)($b['name'] ?? '')));
            }
            return strcmp($as, $bs);
        });

        return view('ext-lazada::orders.pick_list', [
            'order' => $order,
            'orderId' => (string)$orderId,
            'items' => $sorted,
        ]);
    }

    public function logisticsTrace(Request $request, LazadaClient $client, $orderId)
    {
        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
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
            $creds['app_key'],
            $creds['app_secret'],
            $creds['access_token'],
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

    public function shipAndPrintPost(Request $request, LazadaClient $client, $orderId)
    {
        $res = $this->shipAndPrintInternal($request, $client, $orderId, true);
        if (!($res['ok'] ?? false)) {
            return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
                'ok' => false,
                'error' => $res['error'] ?? null,
                'message' => $res['message'] ?? 'Failed to ship & print.',
                'raw' => $res['raw'] ?? null,
            ]);
        }

        return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
            'ok' => true,
            'message' => 'Order set to Ready To Ship. Opening AWB…',
        ])->with('lazada_awb_url', route('ext.lazada.orders.awb', ['orderId' => $orderId]));
    }

    private function shipAndPrintInternal(Request $request, LazadaClient $client, $orderId, bool $returnArray = false)
    {
        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
            return ['ok' => false, 'message' => 'Missing Lazada credentials/token. Please configure Lazada settings and generate access token first.'];
        }

        $order = LazadaOrder::query()->where('region', $setting->region)->where('order_id', (string)$orderId)->first();
        if (!$order) {
            return ['ok' => false, 'message' => 'Order not found in local database. Please fetch orders first.'];
        }

        $passed = $this->pastReadyToShip((string) $orderId);
        if ($passed !== null) {
            return ['ok' => false, 'error' => 'already_done', 'message' => $passed];
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

        // readyToShipReq is mandatory and must be a JSON string; empty params break the signature.
        $readyToShipReq = json_encode([
            'delivery_type' => 'dropship',
            'packages' => $packages,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $rtsRes = $this->runSignedApiCall(
            $client,
            $setting->region,
            $creds['app_key'],
            $creds['app_secret'],
            $creds['access_token'],
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

        $this->afterBooking($client, $setting, (string) $orderId, 'Marked ready to ship order #');

        return ['ok' => true];
    }

    private function afterBooking(LazadaClient $client, object $setting, string $orderId, string $logLabel): void
    {
        try {
            $st = $this->fetchOrderStatusFromApi($client, $setting, $orderId);
            if ($st !== null) {
                LazadaOrder::query()->where('region', $setting->region)->where('order_id', $orderId)->update(['status' => $st]);
            }
            $lo = LazadaOrder::query()->where('region', $setting->region)->where('order_id', $orderId)->first();
            if ($lo) {
                (new LazadaCatalogOrderSync)->sync($lo);
            }
        } catch (\Throwable $e) {
        }

        ActivityLogger::log('updated', 'Lazada Order', null, $logLabel . $orderId);
    }

    private function pastReadyToShip(string $orderId): ?string
    {
        $status = strtolower((string) LazadaOrder::query()->where('order_id', $orderId)->value('status'));

        return match ($status) {
            'ready_to_ship', 'shipped', 'delivered' => 'This order is already ready to ship.',
            'canceled', 'cancelled' => 'This order was cancelled.',
            default => null,
        };
    }

    public function cancelReasons(Request $request, LazadaClient $client, $orderId)
    {
        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
            return response()->json(['ok' => false, 'message' => 'Missing Lazada credentials/token.'], 400);
        }

        $order = LazadaOrder::with('products')->where('order_id', $orderId)->first();
        if (!$order || !$order->products || $order->products->isEmpty()) {
            return response()->json(['ok' => false, 'message' => 'Order or order items not found.'], 404);
        }
        $itemIds = $order->products->pluck('order_item_id')->values()->toArray();

        $res = $this->runSignedApiCall(
            $client,
            $setting->region,
            $creds['app_key'],
            $creds['app_secret'],
            $creds['access_token'],
            'GET',
            '/order/reverse/cancel/validate',
            true,
            [
                'order_id' => (string) $orderId,
                'order_item_id_list' => json_encode($itemIds),
            ],
            'lazada.order.reverse.cancel.validate'
        );

        if (!($res['ok'] ?? false)) {
            return response()->json(['ok' => false, 'message' => 'Failed to validate cancel reasons.'], 500);
        }

        $body = $res['body'] ?? [];
        $code = is_array($body) ? (string) ($body['code'] ?? '') : '';
        if ($code !== '0' && $code !== '') {
            return response()->json(['ok' => false, 'message' => 'Lazada error: ' . ($body['message'] ?? $code)], 400);
        }

        $data = is_array($body) ? ($body['data'] ?? $body) : $body;
        $reasons = $data['reason_options'] ?? $data['reasons'] ?? [];

        return response()->json(['ok' => true, 'data' => $reasons]);
    }

    public function cancel(Request $request, LazadaClient $client, $orderId)
    {
        $request->validate([
            'reason_id' => ['required', 'integer'],
        ]);

        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
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

        $attempt = 0;
        while (true) {
            $cancelRes = $this->runSignedApiCall(
                $client,
                $setting->region,
                $creds['app_key'],
                $creds['app_secret'],
                $creds['access_token'],
                'GET',
                '/order/reverse/cancel/create',
                true,
                [
                    'order_id' => (string) $orderId,
                    'order_item_id_list' => json_encode(array_values(array_map('strval', $items['order_item_ids']))),
                    'reason_id' => (string) $reasonId,
                ],
                'lazada.order.reverse.cancel.create'
            );

            $body = is_array($cancelRes['body'] ?? null) ? $cancelRes['body'] : [];
            $code = (string) ($body['code'] ?? '');

            if ($code === 'ApiCallLimit' && $attempt < 5) {
                $attempt++;
                $banSeconds = preg_match('/\bban\s+will\s+last\s+(\d+)\s+seconds?\b/i', (string) ($body['message'] ?? ''), $mm) ? max(1, (int) $mm[1]) : 1;
                sleep($banSeconds + 1);
                continue;
            }
            break;
        }

        $tip = is_array($body['data'] ?? null) ? $body['data'] : [];
        if (!($cancelRes['ok'] ?? false) || $code !== '0' || strtolower((string) ($tip['tip_type'] ?? '')) === 'error') {
            $why = trim((string) ($tip['tip_content'] ?? '')) ?: trim((string) ($body['message'] ?? '')) ?: 'Lazada did not accept the cancellation.';

            return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'Lazada did not cancel order #' . $orderId . ': ' . $why,
                'raw' => $cancelRes,
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
        try {
            $lo = LazadaOrder::query()->where('region', $setting->region)->where('order_id', (string)$orderId)->first();
            if ($lo) { (new LazadaCatalogOrderSync)->sync($lo); }
        } catch (\Throwable $e) {}

        ActivityLogger::log('updated', 'Lazada Order', null, 'Cancel #' . $orderId);

        return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
            'ok' => true,
            'message' => 'Order cancellation submitted to Lazada.',
        ]);
    }

    public function recreatePackage(Request $request, LazadaClient $client, $orderId)
    {
        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
            return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'Missing Lazada credentials/token. Please configure Lazada settings and generate access token first.',
            ]);
        }

        $items = $this->getOrderItems($client, $setting, $orderId);
        if (!$items['ok']) {
            return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'Failed to fetch order items before repacking.',
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

        $packageIds = [];
        $order = LazadaOrder::query()->where('region', $setting->region)->where('order_id', (string)$orderId)->first();
        if ($order) {
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
        }
        if (empty($packageIds)) {
            $packageIds = $items['package_ids'] ?? [];
        }
        if (empty($packageIds)) {
            return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'No package_id found for this order. Please Pack first.',
            ]);
        }

        $packages = array_map(
            fn ($p) => ['package_id' => (string) $p],
            array_slice(array_values(array_unique(array_filter(array_map('strval', $packageIds)))), 0, 20)
        );

        $repackRes = $this->runSignedApiCall(
            $client,
            $setting->region,
            $creds['app_key'],
            $creds['app_secret'],
            $creds['access_token'],
            'POST',
            '/order/package/repack',
            true,
            [
                'rePackReq' => json_encode(['packages' => $packages], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ],
            'lazada.order.package.repack'
        );

        $body = is_array($repackRes['body'] ?? null) ? $repackRes['body'] : [];
        $result = is_array($body['result'] ?? null) ? $body['result'] : [];
        $answers = (array) ($result['data']['packages'] ?? []);
        $refused = array_values(array_filter($answers, fn ($a) => is_array($a) && (string) ($a['item_err_code'] ?? '') !== '0'));

        if (!($repackRes['ok'] ?? false) || (string) ($body['code'] ?? '0') !== '0' || empty($result['success']) || $answers === [] || $refused !== []) {
            $why = trim((string) ($refused[0]['msg'] ?? '')) ?: trim((string) ($result['error_msg'] ?? '')) ?: trim((string) ($body['message'] ?? '')) ?: 'Lazada did not accept the repack.';

            return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'Lazada did not repack order #' . $orderId . ': ' . $why,
                'raw' => $repackRes,
            ]);
        }

        Storage::disk('local')->delete(\App\Support\Fulfilment\Waybills::relative('lazada', (int) $setting->id, (string) $orderId));

        try {
            $st = $this->fetchOrderStatusFromApi($client, $setting, (string)$orderId);
            if ($st !== null) {
                LazadaOrder::query()->where('region', $setting->region)->where('order_id', (string)$orderId)->update(['status' => $st]);
            }
            $lo = LazadaOrder::query()->where('region', $setting->region)->where('order_id', (string)$orderId)->first();
            if ($lo) { (new LazadaCatalogOrderSync)->sync($lo); }
        } catch (\Throwable $e) {
        }

        return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
            'ok' => true,
            'message' => 'Repacked on Lazada. The order should return to To Pack.',
            'raw' => $repackRes,
        ]);
    }


    public function fetch(Request $request, LazadaClient $client)
    {
        $data = $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'status' => 'nullable|string|max:64',
            'no_stock' => 'nullable',
        ]);

        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || empty($creds['complete'])) {
            $modeLabel = (($setting->mode ?? 'live') === 'sandbox') ? 'sandbox' : 'production';
            return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => "Missing {$modeLabel} Lazada credentials/token. Configure Lazada settings for the active mode and ensure an access token is set.",
            ]);
        }

        $runner = app(\App\Services\OrderFetchRunner::class);
        $run = $runner->begin($this->orderFetchIntegration(), $this->orderFetchStoreId($request), $data['date_from'], $data['date_to'], [
            'status' => $data['status'] ?? null, 'no_stock' => ! empty($data['no_stock']),
        ]);
        $run = $runner->walk($run, fn (\App\Models\OrderFetchRun $r) => $this->orderFetchStep($r));

        $redirectParams = ['date_from' => $data['date_from'], 'date_to' => $data['date_to']];
        if (!empty($data['status'])) {
            $redirectParams['status'] = $data['status'];
        }
        $message = $run->status === \App\Models\OrderFetchRun::FAILED
            ? (string) $run->last_error
            : \App\Services\OrderFetchRunner::outcome($run) . ($run->status === \App\Models\OrderFetchRun::RUNNING ? ' The page continues the rest.' : '');

        return redirect()->route('ext.lazada.orders.index', $redirectParams)
            ->with('lazada_orders_last_result', [
                'ok' => $run->status !== \App\Models\OrderFetchRun::FAILED,
                'message' => $message,
                'saved' => (int) $run->created,
                'skipped' => 0,
                'run' => $run->id,
            ]);
    }

    protected function orderFetchIntegration(): string
    {
        return 'lazada';
    }

    protected function orderFetchStoreId(Request $request): ?int
    {
        return LazadaSetting::defaultStore()?->id;
    }

    public function orderFetchStep(\App\Models\OrderFetchRun $run): array
    {
        $client = app(LazadaClient::class);
        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || empty($creds['complete'])) {
            return ['error' => 'This store is not connected to Lazada.'];
        }
        $skipStock = (bool) ($run->options['no_stock'] ?? false);
        $tz = new \DateTimeZone('Asia/Manila');
        $pageLimit = 100;
        $offset = (int) ($run->cursor['offset'] ?? 0);
        $params = [
            'limit' => $pageLimit,
            'offset' => $offset,
            'created_after' => (new \DateTime($run->date_from->format('Y-m-d'), $tz))->setTime(0, 0, 0)->format('Y-m-d\\TH:i:sP'),
            'created_before' => (new \DateTime($run->date_to->format('Y-m-d'), $tz))->setTime(23, 59, 59)->format('Y-m-d\\TH:i:sP'),
        ];
        if (!empty($run->options['status'])) {
            $params['status'] = (string) $run->options['status'];
        }

        $res = $this->runSignedApiCall($client, $setting->region, $creds['app_key'], $creds['app_secret'], $creds['access_token'], 'GET', '/orders/get', true, $params, 'lazada.orders.get');
        $body = $res['body'] ?? [];
        if (!($res['ok'] ?? false)) {
            return ['error' => 'Lazada did not answer: ' . (is_array($body) ? (string) ($body['message'] ?? $body['msg'] ?? 'no answer') : 'no answer')];
        }
        $dataNode = is_array($body) ? ($body['data'] ?? $body) : [];
        $pageOrders = $dataNode['orders'] ?? $dataNode['order_list'] ?? $dataNode['orders_list'] ?? $dataNode['data'] ?? [];
        if (!is_array($pageOrders)) {
            $pageOrders = [];
        }
        $total = isset($dataNode['countTotal']) ? (int) $dataNode['countTotal'] : (isset($dataNode['count']) ? (int) $dataNode['count'] : null);

        $created = 0;
        $updated = 0;
        $read = 0;
        foreach ($pageOrders as $o) {
            if (!is_array($o)) continue;
            $orderId = (string) ($o['order_id'] ?? $o['orderId'] ?? $o['order_number'] ?? $o['orderNumber'] ?? '');
            if ($orderId === '') continue;
            $read++;
            $statusVal = $o['statuses'] ?? $o['status'] ?? $o['order_status'] ?? null;
            if (is_array($statusVal)) {
                $statusVal = $statusVal[0] ?? null;
            }
            $payload = [
                'region' => $setting->region,
                'order_id' => $orderId,
                'status' => is_string($statusVal) ? $statusVal : null,
                'order_created_at' => $this->parseApiDatetime($o['created_at'] ?? $o['createdAt'] ?? $o['created_time'] ?? null),
                'order_updated_at' => $this->parseApiDatetime($o['updated_at'] ?? $o['updatedAt'] ?? $o['update_time'] ?? null),
                'raw' => $o,
            ];
            $existing = LazadaOrder::query()->where('region', $setting->region)->where('order_id', $orderId)->first();
            if ($existing) {
                $oldStatus = $existing->status;
                $existing->fill($payload)->save();
                $updated++;
                $needsProductSync = ($oldStatus !== $existing->status);
                if (!$needsProductSync) {
                    $firstProduct = $existing->products()->first();
                    $fpRaw = $firstProduct ? ($firstProduct->raw ?? []) : [];
                    $needsProductSync = !$firstProduct
                        || trim((string) ($fpRaw['shipment_provider'] ?? '')) === ''
                        || trim((string) ($fpRaw['tracking_code'] ?? '')) === '';
                }
                if ($needsProductSync) {
                    try { $this->syncOrderProductsFromApi($client, $setting, $existing); } catch (\Throwable $e) {}
                }
                try { (new LazadaCatalogOrderSync)->setSkipStockAdjust($skipStock)->sync($existing); } catch (\Throwable $e) {}
                continue;
            }
            $newOrder = LazadaOrder::query()->create($payload);
            $created++;
            try { $this->syncOrderProductsFromApi($client, $setting, $newOrder); } catch (\Throwable $e) {}
            try { (new LazadaCatalogOrderSync)->setSkipStockAdjust($skipStock)->sync($newOrder); } catch (\Throwable $e) {}
        }

        $done = count($pageOrders) < $pageLimit;
        $answer = ['read' => $read, 'created' => $created, 'updated' => $updated, 'failed' => 0, 'done' => $done, 'cursor' => $done ? null : ['offset' => $offset + $pageLimit]];
        if ($total !== null) {
            $answer['total'] = $total;
            $answer['pages'] = (int) max(1, ceil($total / $pageLimit));
        }

        return $answer;
    }

	public function updateStatuses(Request $request, LazadaClient $client)
	{
	    try { @set_time_limit(0); } catch (\Throwable $e) {}

	    $data = $request->validate([
	        'date_from' => 'required|date',
	        'date_to' => 'required|date|after_or_equal:date_from',
	    ]);

	    $setting = LazadaSetting::defaultStore()?->decrypted();
	    $creds = LazadaSetting::activeCredentials($setting);
	    if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
	        return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
	            'ok' => false,
	            'message' => 'Missing Lazada credentials/token. Please configure Lazada settings and generate access token first.',
	        ]);
	    }

	    $tz = new \DateTimeZone('Asia/Manila');
	    $updateAfter = (new \DateTime($data['date_from'], $tz))->setTime(0, 0, 0)->format('Y-m-d\\TH:i:sP');

	    $baseParams = [
	        'limit' => 50,
	        'update_after' => $updateAfter,
	    ];

	    $orders = [];
	    $res = null;
	    for ($offset = 0; $offset <= 450; $offset += 50) {
	        $params = $baseParams;
	        $params['offset'] = $offset;

	        $res = $this->runSignedApiCall(
	            $client,
	            $setting->region,
	            $creds['app_key'],
	            $creds['app_secret'],
	            $creds['access_token'],
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
                $oldStatus = $existing->status;
                $existing->fill($payload)->save();
                $updated++;
                $needsProductSync = ($oldStatus !== $existing->status);
                if (!$needsProductSync) {
                    $firstProduct = $existing->products()->first();
                    $fpRaw = $firstProduct ? ($firstProduct->raw ?? []) : [];
                    $needsProductSync = !$firstProduct
                        || trim((string)($fpRaw['shipment_provider'] ?? '')) === ''
                        || trim((string)($fpRaw['tracking_code'] ?? '')) === '';
                }
                if ($needsProductSync) {
                    try { $this->syncOrderProductsFromApi($client, $setting, $existing); } catch (\Throwable $e) {}
                }
                try { (new LazadaCatalogOrderSync)->sync($existing); } catch (\Throwable $e) {}
            } else {
                $skipped++;
            }
	    }

	    $message = ($updated === 0 && $skipped === 0)
            ? 'Orders are already up to date.'
            : 'Orders updated. Updated ' . $updated . ($skipped ? (', skipped ' . $skipped . ' not-yet-synced order(s). Use Fetch Orders to add them.') : '.') ;

	    $count = $updated + $skipped;
	    ActivityLogger::log('updated', 'Lazada Order', null, 'Synced statuses for ' . $count . ' order(s)');

	    return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
	        'ok' => true,
	        'message' => $message,
	    ]);
	}


public function reset(Request $request)
{
    $request->validate([
        'confirm' => 'required|in:RESET',
        'password' => 'required|string',
    ]);

    if (!Hash::check($request->input('password'), auth()->user()->password)) {
        return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
            'ok' => false,
            'message' => 'Incorrect password. Reset cancelled.',
        ]);
    }

    $setting = LazadaSetting::defaultStore()?->decrypted();
    $creds = LazadaSetting::activeCredentials($setting);
    if (!$setting || !$setting->region) {
        return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
            'ok' => false,
            'message' => 'Missing Lazada region setting.',
        ]);
    }

    try {
        $count = LazadaOrder::query()->where('region', $setting->region)->count();

        \DB::transaction(function () use ($setting) {
            LazadaOrder::query()->where('region', $setting->region)->delete();
        });

        ActivityLogger::log('deleted', 'Lazada Order', null, 'Reset ' . $count . ' Lazada order(s)');

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




    public function pack(Request $request, LazadaClient $client, $orderId)
    {
        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
            return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'Missing Lazada credentials/token. Please configure Lazada settings and generate access token first.',
            ]);
        }

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

        if (count($items['package_ids']) > 0) {
            return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
                'ok' => false,
                'error' => 'already_done',
                'message' => 'This order is already packed.',
            ]);
        }

        if (count($items['order_item_ids']) === 0) {
            return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'No order_item_ids found for this order.',
                'raw' => $items['raw'],
            ]);
        }

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
            $creds['app_key'],
            $creds['app_secret'],
            $creds['access_token'],
            'POST',
            '/order/fulfill/pack',
            true,
            [
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

        $this->afterBooking($client, $setting, (string) $orderId, 'Packed order #');

        return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
            'ok' => true,
            'message' => 'Packed. You can now try Print AWB (and then Ready To Ship).',
            'raw' => $packRes,
        ]);
    }

    public function rts(Request $request, LazadaClient $client, $orderId)
    {
        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
            return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'Missing Lazada credentials/token. Please configure Lazada settings and generate access token first.',
            ]);
        }

        $passed = $this->pastReadyToShip((string) $orderId);
        if ($passed !== null) {
            return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
                'ok' => false,
                'error' => 'already_done',
                'message' => $passed,
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

        $readyToShipReq = json_encode([
            'delivery_type' => 'dropship',
            'packages' => $packages,
        ], JSON_UNESCAPED_SLASHES);

        $rtsRes = $this->runSignedApiCall(
            $client,
            $setting->region,
            $creds['app_key'],
            $creds['app_secret'],
            $creds['access_token'],
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

        $this->afterBooking($client, $setting, (string) $orderId, 'Marked ready to ship order #');

        return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
            'ok' => true,
            'message' => 'Marked Ready To Ship.',
            'raw' => $rtsRes,
        ]);
    }

    private const WAYBILL_UNREACHABLE = 'The waybill could not be downloaded from Lazada. Try again in a moment.';

    private function downloadPdf(string $url): ?string
    {
        if (!preg_match('#^https://#i', $url)) {
            return null;
        }

        try {
            $resp = Http::timeout(30)->withHeaders(['User-Agent' => 'LaravelERP/1.0'])->get($url);
        } catch (\Throwable $e) {
            return null;
        }

        $bin = $resp->ok() ? $resp->body() : '';

        return is_string($bin) && str_starts_with($bin, '%PDF') ? $bin : null;
    }

    private function awbError(string $message)
    {
        return response('<html><body style="font-family:system-ui,sans-serif;padding:40px;"><h3 style="color:#dc3545;">AWB Error</h3><p>' . e($message) . '</p></body></html>', 422)
            ->header('Content-Type', 'text/html');
    }

    public function awbPdf(Request $request, LazadaClient $client, $orderId)
    {
        $safeOrderId = preg_replace('/[^0-9A-Za-z_-]/', '', (string) $orderId);
        $localPath = \App\Support\Fulfilment\Waybills::path('lazada', (int) LazadaSetting::defaultStore()?->id, (string) $orderId);

        if (file_exists($localPath)) {
            return response(file_get_contents($localPath), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="awb_' . $safeOrderId . '.pdf"',
            ]);
        }

        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
            return $this->awbError('Missing Lazada credentials/token. Please configure Lazada settings and generate access token first.');
        }

        $items = $this->getOrderItems($client, $setting, $orderId);

        if (!$items['ok']) {
            return $this->awbError('Failed to fetch order items.');
        }

        if ($items['is_sof']) {
            return $this->awbError('Lazada error: 50008 - not support operation for sof order');
        }

        if (count($items['package_ids']) === 0) {
            return $this->awbError('No packages support printing. Click Pack first to generate packages.');
        }

        $packages = array_map(function ($pid) {
            return ['package_id' => $pid];
        }, $items['package_ids']);

        $getDocumentReq = json_encode([
            'doc_type' => 'PDF',
            'packages' => $packages,
        ], JSON_UNESCAPED_SLASHES);

        $awbRes = $this->runSignedApiCall(
            $client,
            $setting->region,
            $creds['app_key'],
            $creds['app_secret'],
            $creds['access_token'],
            'POST',
            '/order/package/document/get',
            true,
            [
                'getDocumentReq' => $getDocumentReq,
            ],
            'lazada.order.package.document.get'
        );

        if (!($awbRes['ok'] ?? false)) {
            return $this->awbError('Failed to retrieve AWB PDF.');
        }

        $awbBody = $awbRes['body'] ?? [];

        if (is_array($awbBody) && isset($awbBody['code']) && isset($awbBody['message'])) {
            return $this->awbError('Lazada error: ' . $awbBody['code'] . ' - ' . $awbBody['message']);
        }
        $dataNode = is_array($awbBody) ? ($awbBody['data'] ?? $awbBody) : $awbBody;

        if (is_array($dataNode) && isset($dataNode['code']) && isset($dataNode['message'])) {
            $code = (string)$dataNode['code'];
            $msg = (string)$dataNode['message'];

            if ($code === '700040') {
                $msg .= ' (No printable package yet. The order must be packed or ready to ship first.)';
            } elseif ($code === '50008') {
                $msg .= ' (SOF/DBS orders are not supported by this API.)';
            }

            return $this->awbError('Lazada error: ' . $code . ' - ' . $msg);
        }

        $doc = $this->findDocument($dataNode);
        if (!$doc) {
            return $this->awbError('AWB PDF response did not contain a document.');
        }

        $storePath = \App\Support\Fulfilment\Waybills::relative('lazada', (int) $setting->id, (string) $orderId);

        if (($doc['type'] ?? '') === 'url') {
            $bin = $this->downloadPdf((string) ($doc['value'] ?? ''));
            if ($bin === null) {
                return $this->awbError(self::WAYBILL_UNREACHABLE);
            }
        } else {
            $bin = base64_decode((string) $doc['value'], true);
        }
        if ($bin === false) {
            return $this->redirectBackOrIndex($request)->with('lazada_orders_last_result', [
                'ok' => false,
                'message' => 'Invalid base64 document returned from Lazada.',
                'raw' => $awbRes,
            ]);
        }

        if (!str_starts_with($bin, '%PDF') && preg_match('/<iframe[^>]+src="([^"]+)"/i', $bin, $m)) {
            $bin = $this->downloadPdf((string) $m[1]);
            if ($bin === null) {
                return $this->awbError(self::WAYBILL_UNREACHABLE);
            }
        }

        if (!str_starts_with($bin, '%PDF')) {
            return $this->awbError('Lazada returned a document that is not a PDF.');
        }

        try {
            Storage::disk('local')->put($storePath, $bin);
        } catch (\Throwable $e) {
        }

        return response($bin, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="awb_' . $orderId . '.pdf"',
        ]);
    }

    private function fetchOrderStatusFromApi(LazadaClient $client, $setting, string $orderId): ?string
    {
        $creds = LazadaSetting::activeCredentials($setting);

        $res = $this->runSignedApiCall(
            $client,
            $setting->region,
            $creds['app_key'],
            $creds['app_secret'],
            $creds['access_token'],
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

    private function syncOrderProductsFromApi(LazadaClient $client, $setting, LazadaOrder $order): ?array
    {
        $oid = (string)($order->order_id ?? '');
        if ($oid === '') return null;

        $creds = LazadaSetting::activeCredentials($setting);

        $itemsRes = $this->runSignedApiCall(
            $client,
            $setting->region,
            $creds['app_key'],
            $creds['app_secret'],
            $creds['access_token'],
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

            $variation = null;
            foreach (['variation', 'sku_variant', 'variation_sku', 'variation_name', 'variation_detail', 'item_variation'] as $k) {
                if (isset($it[$k]) && is_string($it[$k]) && trim($it[$k]) !== '') {
                    $variation = trim($it[$k]);
                    break;
                }
            }
            if ($variation === null) {
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

            $itemPrice = isset($it['item_price']) && is_numeric($it['item_price']) ? (float) $it['item_price'] : null;
            $paidPrice = isset($it['paid_price']) && is_numeric($it['paid_price']) ? (float) $it['paid_price'] : null;

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
                    'item_price' => $itemPrice,
                    'paid_price' => $paidPrice,
                    'image' => $image !== '' ? $image : null,
                    'status' => isset($it['status']) && is_string($it['status']) ? $it['status'] : null,
                    'raw' => $it,
                ]
            );

            $out[] = $row;
        }

        return $out;
    }

    private function getOrderItems(LazadaClient $client, $setting, $orderId): array
    {
        $creds = LazadaSetting::activeCredentials($setting);

        $itemsRes = $this->runSignedApiCall(
            $client,
            $setting->region,
            $creds['app_key'],
            $creds['app_secret'],
            $creds['access_token'],
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

    private function buildPrintableItems(LazadaClient $client, $setting, $orderId, ?LazadaOrder $order = null): array
    {
        $creds = LazadaSetting::activeCredentials($setting);

        $items = [];

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

        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
            return ['ok' => false, 'items' => [], 'raw' => ['message' => 'Missing Lazada credentials/token for items fallback']];
        }

        $itemsRes = $this->runSignedApiCall(
            $client,
            $setting->region,
            $creds['app_key'],
            $creds['app_secret'],
            $creds['access_token'],
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

    private function getSellerId(LazadaClient $client, $setting): string
    {
        $creds = LazadaSetting::activeCredentials($setting);

        $region = (string)($setting->region ?? '');
        $appKey = (string)($creds['app_key'] ?? '');
        $accessToken = (string)($creds['access_token'] ?? '');
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
            (string)$creds['app_key'],
            (string)$creds['app_secret'],
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
        if (is_array($data)) {
            foreach (['pdf_url', 'document_url', 'url', 'download_url', 'file_url'] as $k) {
                if (isset($data[$k]) && is_string($data[$k]) && str_starts_with($data[$k], 'http')) {
                    return ['type' => 'url', 'value' => $data[$k]];
                }
            }

            foreach (['file', 'document', 'pdf', 'awb_pdf', 'awbPdf'] as $k) {
                if (!isset($data[$k])) continue;

                if (is_string($data[$k]) && strlen($data[$k]) > 50) {
                    return ['type' => 'base64', 'value' => $data[$k]];
                }

                if (is_array($data[$k])) {
                    $nested = $this->findDocument($data[$k]);
                    if ($nested) return $nested;
                }
            }

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
    
    
            if ($apiPath === '/category/brands/query') {
                $pageNo = isset($params['page_no']) ? (int)$params['page_no'] : null;
                $pageSize = isset($params['page_size']) ? (int)$params['page_size'] : null;
    
                if ($pageNo !== null && $pageSize !== null) {
                    $params['startRow'] = max(0, ($pageNo - 1) * $pageSize);
                    $params['pageSize'] = max(1, $pageSize);
                    unset($params['page_no'], $params['page_size']);
                } else {
                    if (isset($params['startRow']) && !isset($params['pageSize']) && isset($params['page_size'])) {
                        $params['pageSize'] = (int)$params['page_size'];
                        unset($params['page_size']);
                    }
                }
            }
    
            $method = strtoupper($method);

            $rateKey = 'lazada_api_last_call:' . $region . ':' . $appKey;
            $lockKey = 'lazada_api_lock:' . $region . ':' . $appKey;
            $minIntervalMs = app()->runningUnitTests() ? 0 : 1200;

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
                if ($code === 'SellerCallLimit' || $code === 'ApiCallLimit') {
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


    private function parseApiDatetime($value): ?string
    {
        if ($value === null) return null;

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $num = (int)$value;
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

        return redirect()->route('ext.lazada.orders.index', $qs);
    }

}
