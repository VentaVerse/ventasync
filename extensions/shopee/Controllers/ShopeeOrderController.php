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
use Extensions\shopee\Services\ShopeeOrdersPanel;
use Extensions\shopee\Services\ShopeeReturnsPanel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ShopeeOrderController extends Controller
{
    use \App\Http\Controllers\Concerns\DrivesOrderFetchRuns;

    public function index(Request $request, ShopeeOrdersPanel $panel)
    {
        return view('ext-shopee::orders.index', $panel->build($request));
    }

    public function returns(Request $request, ShopeeReturnsPanel $panel)
    {
        return view('ext-shopee::orders.returns', $panel->build($request));
    }

    /**
     * Rich, order-style detail view for a single Shopee return.
     * Read-only (Phase 1): renders data already captured in the return's raw payload.
     */
    /**
     * What Shopee will actually let us offer on this return.
     *
     * A seller cannot propose any resolution on any return: what is permitted
     * depends on the return's state, its reason, the market and what the buyer
     * asked for. /api/v2/returns/get_available_solutions answers that per
     * return, and it answers with two objects - offer_return_refund (send it
     * back, then get money) and offer_refund (keep it, get money anyway) -
     * each carrying eligibility, whether the amount can be countered, and the
     * ceiling if it can.
     *
     * WHY THIS ONE FIRST, AND ALONE. It is a READ, so it sits in the same
     * permission tier as the return list and can be verified against live
     * without pushing anything to a marketplace. The write side (offer,
     * accept_offer, dispute, confirm) cannot be exercised in our sandbox at
     * all, and the failure mode of guessing is a button that a customer
     * watches fail. This call is what lets the interface offer only choices
     * that exist.
     *
     * FAILS CLOSED. A solution is reported available only when Shopee says
     * eligibility is true. A refused call, a missing token or an unparseable
     * body all report nothing available with the reason attached - never a
     * silent empty list, which would read as "no options" rather than "we did
     * not get to ask".
     */
    public function returnSolutions(Request $request, ShopeeClient $client, string $returnSn)
    {
        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);

        if (!$auth || empty($auth['access_token']) || empty($auth['shop_id'])) {
            return response()->json([
                'ok' => false,
                'message' => 'Shopee is not connected. Reconnect in settings before asking what can be offered.',
            ], 200);
        }

        $res = $client->shopGet(
            $auth['mode'],
            (int) $auth['partner_id'],
            (string) $auth['partner_key'],
            (string) $auth['access_token'],
            (int) $auth['shop_id'],
            '/api/v2/returns/get_available_solutions',
            ['return_sn' => $returnSn]
        );

        ShopeeApiLog::safeCreate([
            'pack'            => 'shopee.returns.get_available_solutions',
            'method'          => 'GET',
            'api_path'        => '/api/v2/returns/get_available_solutions',
            'auth_required'   => true,
            'request_params'  => ['return_sn' => $returnSn],
            'response_status' => (int) ($res['status'] ?? 0),
            'ok'              => (bool) ($res['ok'] ?? false),
            'response_body'   => $res['body'] ?? null,
        ]);

        $body = $res['body'] ?? [];
        $error = is_array($body) ? trim((string) ($body['error'] ?? '')) : '';

        if (!($res['ok'] ?? false) || $error !== '') {
            $message = is_array($body) ? trim((string) ($body['message'] ?? '')) : '';

            return response()->json([
                'ok' => false,
                'message' => $message !== ''
                    ? 'Shopee said: ' . $message
                    : 'Shopee did not answer. Nothing is offered here until it does.',
            ], 200);
        }

        $data = (is_array($body) ? ($body['response'] ?? []) : []) ?: [];

        return response()->json([
            'ok' => true,
            'return_sn' => (string) ($data['return_sn'] ?? $returnSn),
            'solutions' => [
                self::readSolution($data, 'offer_return_refund', 'Return and refund',
                    'The buyer sends the item back, then is refunded.'),
                self::readSolution($data, 'offer_refund', 'Refund only',
                    'The buyer keeps the item and is refunded anyway.'),
            ],
        ]);
    }

    /**
     * One solution object, read conservatively.
     *
     * max_refund_amount is documented as present only when the amount is
     * adjustable, so reading it unconditionally yields null - and a null
     * rendered as a number is a ceiling of zero, which is a lie about how much
     * can be offered.
     */
    private static function readSolution(array $data, string $key, string $label, string $blurb): array
    {
        $node = is_array($data[$key] ?? null) ? $data[$key] : [];
        $eligible = ($node['eligibility'] ?? false) === true || ($node['eligibility'] ?? null) === 'true';
        $adjustable = ($node['refund_amount_adjustable'] ?? false) === true;
        $max = $node['max_refund_amount'] ?? null;

        return [
            'key' => $key,
            'label' => $label,
            'blurb' => $blurb,
            'eligible' => $eligible,
            'adjustable' => $adjustable && $eligible,
            'max_refund_amount' => ($adjustable && $eligible && is_numeric($max)) ? (float) $max : null,
        ];
    }

    public function returnDetail(Request $request, string $returnSn)
    {
        $settingRaw = ShopeeSetting::defaultStore();
        $setting = $settingRaw?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        $region = $auth['region'] ?? 'ph';

        $return = ShopeeReturn::query()
            ->where('region', $region)
            ->where('return_sn', $returnSn)
            ->firstOrFail();

        // Ordered-qty map from the linked Shopee order, keyed by item_id_model_id,
        // so each returned line can show "returning X of Y ordered".
        $orderedQty = [];
        $return->loadMissing('order.products');
        if ($return->order) {
            foreach ($return->order->products as $p) {
                $key = ((string) $p->item_id) . '_' . ((string) $p->model_id);
                $orderedQty[$key] = ($orderedQty[$key] ?? 0) + (int) $p->quantity;
            }
        }

        return view('ext-shopee::orders.return-detail', [
            'setting'    => $settingRaw,
            'return'     => $return,
            'raw'        => is_array($return->raw) ? $return->raw : [],
            'orderedQty' => $orderedQty,
        ]);
    }

    public function show(Request $request, ShopeeClient $client, string $orderSn)
    {
        $orderSn = trim($orderSn);
        if ($orderSn === '' || mb_strlen($orderSn) > 80) {
            abort(404);
        }

        $settingRaw = ShopeeSetting::defaultStore();
        $setting = $settingRaw?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        $region = $auth['region'] ?? 'ph';

        $order = ShopeeOrder::query()
            ->where('region', $region)
            ->where('order_sn', $orderSn)
            ->with('products')
            ->firstOrFail();

        $apiError = null;

        $refresh = (bool) $request->boolean('refresh');
        $hasCreds = $setting && $auth['complete'];

        // Both pull-and-persist branches below are manage-tier only. A
        // view-tier operator (view_shopee_orders without manage_shopee_orders)
        // must always see the page rendered from stored data — never a live
        // API pull, never a write — regardless of the `refresh` query string.
        $canManage = (bool) $request->user()?->hasPermission('manage_shopee/order');

        if ($hasCreds && $canManage && $refresh) {
            try {
                $res = $client->shopGet(
                    $auth['mode'],
                    (int) $auth['partner_id'],
                    (string) $auth['partner_key'],
                    (string) $auth['access_token'],
                    (int) $auth['shop_id'],
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
                                    $auth['mode'],
                                    (int) $auth['partner_id'],
                                    (string) $auth['partner_key'],
                                    (string) $auth['access_token'],
                                    (int) $auth['shop_id'],
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

        // Fetch escrow detail (fees) if not already stored or on refresh.
        // This branch fires even with no `refresh` param whenever fees are
        // empty, so it needs the same manage gate as the block above.
        if ($hasCreds && $canManage && (empty($order->fees) || $refresh)) {
            try {
                $escrowRes = $client->shopGet(
                    $auth['mode'],
                    (int) $auth['partner_id'],
                    (string) $auth['partner_key'],
                    (string) $auth['access_token'],
                    (int) $auth['shop_id'],
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

    /**
     * Fetch orders for a window of dates, as a run of one-page steps
     * (App\Services\OrderFetchRunner, 2026-09-07): the first pages ride
     * this request, the page continues the rest one request per page.
     */
    public function fetch(Request $request, ShopeeClient $client)
    {
        $data = $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'no_stock' => 'nullable',
        ]);

        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return redirect()->route('ext.shopee.orders.index')->with('shopee_orders_last_result', [
                'ok' => false,
                'message' => "Missing Shopee {$modeLabel} credentials/token. Please configure Shopee settings first.",
            ]);
        }

        $runner = app(\App\Services\OrderFetchRunner::class);
        $run = $runner->begin($this->orderFetchIntegration(), $this->orderFetchStoreId($request), $data['date_from'], $data['date_to'], [
            'no_stock' => ! empty($data['no_stock']),
        ]);
        $run = $runner->walk($run, fn (\App\Models\OrderFetchRun $r) => $this->orderFetchStep($r));

        $message = $run->status === \App\Models\OrderFetchRun::FAILED
            ? (string) $run->last_error
            : \App\Services\OrderFetchRunner::outcome($run) . ($run->status === \App\Models\OrderFetchRun::RUNNING ? ' The page continues the rest.' : '');

        return redirect()->route('ext.shopee.orders.index')->with('shopee_orders_last_result', [
            'ok' => $run->status !== \App\Models\OrderFetchRun::FAILED,
            'message' => $message,
            'run' => $run->id,
        ]);
    }

    protected function orderFetchIntegration(): string
    {
        return 'shopee';
    }

    protected function orderFetchStoreId(Request $request): ?int
    {
        return ShopeeSetting::defaultStore()?->id;
    }

    /**
     * One page of get_order_list inside the current fifteen-day window
     * (Shopee's largest), then the details of that page's orders in
     * batches of fifty, saved as they arrive. The cursor is the window and
     * Shopee's own cursor inside it; the next window begins when Shopee
     * says there is no more in this one.
     */
    public function orderFetchStep(\App\Models\OrderFetchRun $run): array
    {
        $client = app(ShopeeClient::class);
        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            return ['error' => 'This store is not connected to Shopee.'];
        }
        $skipStock = (bool) ($run->options['no_stock'] ?? false);
        $tz = new \DateTimeZone('Asia/Manila');
        $timeFrom = (new \DateTime($run->date_from->format('Y-m-d'), $tz))->setTime(0, 0, 0)->getTimestamp();
        $timeTo = (new \DateTime($run->date_to->format('Y-m-d'), $tz))->setTime(23, 59, 59)->getTimestamp();
        $windowSize = 15 * 86400;

        $windowFrom = (int) ($run->cursor['window_from'] ?? $timeFrom);
        $cursor = (string) ($run->cursor['cursor'] ?? '');
        $windowTo = min($windowFrom + $windowSize, $timeTo);

        $extraQuery = [
            'time_range_field' => 'create_time',
            'time_from' => $windowFrom,
            'time_to' => $windowTo,
            'page_size' => 100,
        ];
        if ($cursor !== '') {
            $extraQuery['cursor'] = $cursor;
        }
        $res = $client->shopGet($auth['mode'], (int) $auth['partner_id'], (string) $auth['partner_key'], (string) $auth['access_token'], (int) $auth['shop_id'], '/api/v2/order/get_order_list', $extraQuery);
        ShopeeApiLog::safeCreate([
            'pack' => 'shopee.order.get_order_list', 'method' => 'GET', 'api_path' => '/api/v2/order/get_order_list',
            'auth_required' => true, 'request_params' => $extraQuery,
            'response_status' => (int) ($res['status'] ?? 0), 'ok' => (bool) ($res['ok'] ?? false), 'response_body' => $res['body'] ?? null,
        ]);
        $body = $res['body'] ?? [];
        if (!($res['ok'] ?? false) || (is_array($body) && (string) ($body['error'] ?? '') !== '')) {
            return ['error' => 'Shopee did not answer: ' . (is_array($body) ? (string) ($body['message'] ?? $body['msg'] ?? $body['error'] ?? 'no answer') : 'no answer')];
        }
        $respData = $body['response'] ?? $body;
        $sns = [];
        foreach ((array) ($respData['order_list'] ?? []) as $o) {
            $sn = (string) ($o['order_sn'] ?? '');
            if ($sn !== '') {
                $sns[] = $sn;
            }
        }
        $more = (bool) ($respData['more'] ?? false);
        $nextCursor = (string) ($respData['next_cursor'] ?? '');

        $created = 0;
        $failed = 0;
        $read = 0;
        foreach (array_chunk($sns, 50) as $chunk) {
            $detailRes = $client->shopGet($auth['mode'], (int) $auth['partner_id'], (string) $auth['partner_key'], (string) $auth['access_token'], (int) $auth['shop_id'], '/api/v2/order/get_order_detail', [
                'order_sn_list' => implode(',', $chunk),
                'response_optional_fields' => 'buyer_username,recipient_address,total_amount,item_list,pay_time,shipping_carrier,tracking_no,payment_method,order_chargeable_weight_gram,note,currency,actual_shipping_fee,estimated_shipping_fee,invoice_data,buyer_cancel_reason,cancel_by,cancel_reason,fulfillment_flag,pickup_done_time,package_list',
            ]);
            ShopeeApiLog::safeCreate([
                'pack' => 'shopee.order.get_order_detail', 'method' => 'GET', 'api_path' => '/api/v2/order/get_order_detail',
                'auth_required' => true, 'request_params' => ['order_sn_list' => implode(',', $chunk)],
                'response_status' => (int) ($detailRes['status'] ?? 0), 'ok' => (bool) ($detailRes['ok'] ?? false), 'response_body' => $detailRes['body'] ?? null,
            ]);
            if (!($detailRes['ok'] ?? false)) {
                $failed += count($chunk);
                continue;
            }
            $detailBody = $detailRes['body'] ?? [];
            $detailResp = $detailBody['response'] ?? $detailBody;
            foreach ((array) ($detailResp['order_list'] ?? []) as $o) {
                if (!is_array($o)) continue;
                $orderSn = (string) ($o['order_sn'] ?? '');
                if ($orderSn === '') continue;
                $read++;
                if (ShopeeOrder::query()->where('region', $auth['region'] ?? 'ph')->where('order_sn', $orderSn)->exists()) {
                    continue; // already held: a Fetch never rewrites what Update statuses maintains
                }
                try {
                    $newOrder = ShopeeOrder::query()->create([
                        'region' => $auth['region'] ?? 'ph',
                        'order_sn' => $orderSn,
                        'status' => (string) ($o['order_status'] ?? ''),
                        'order_created_at' => $this->parseTimestamp($o['create_time'] ?? null),
                        'order_updated_at' => $this->parseTimestamp($o['update_time'] ?? null),
                        'raw' => $o,
                    ]);
                    $created++;
                    try { $this->syncOrderProductsFromDetail($newOrder, $o); } catch (\Throwable $e) {}
                    try { (new ShopeeCatalogOrderSync)->setSkipStockAdjust($skipStock)->sync($newOrder); } catch (\Throwable $e) {}
                } catch (\Throwable $e) {
                    $failed++;
                }
            }
        }

        // Where the next page is: the same window with Shopee's cursor, else the next window, else done.
        if ($more && $nextCursor !== '') {
            $next = ['window_from' => $windowFrom, 'cursor' => $nextCursor];
        } elseif ($windowTo < $timeTo) {
            $next = ['window_from' => $windowTo, 'cursor' => ''];
        } else {
            $next = null;
        }
        $windows = (int) max(1, ceil(($timeTo - $timeFrom) / $windowSize));

        return ['read' => $read, 'created' => $created, 'updated' => 0, 'failed' => $failed, 'done' => $next === null, 'cursor' => $next,
            'note' => \App\Services\OrderFetchRunner::pageSentence($read, $created, 0, $failed) . ($windows > 1 ? ' (window ' . ((int) floor(($windowFrom - $timeFrom) / $windowSize) + 1) . ' of ' . $windows . ')' : '')];
    }

    public function updateStatuses(Request $request, ShopeeClient $client)
    {
        try { @set_time_limit(0); } catch (\Throwable $e) {}

        $data = $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
        ]);

        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return redirect()->route('ext.shopee.orders.index')->with('shopee_orders_last_result', [
                'ok' => false,
                'message' => "Missing Shopee {$modeLabel} credentials/token.",
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
                    $auth['mode'],
                    (int) $auth['partner_id'],
                    (string) $auth['partner_key'],
                    (string) $auth['access_token'],
                    (int) $auth['shop_id'],
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
                $auth['mode'],
                (int) $auth['partner_id'],
                (string) $auth['partner_key'],
                (string) $auth['access_token'],
                (int) $auth['shop_id'],
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
                    ->where('region', $auth['region'] ?? 'ph')
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
        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return response()->json(['ok' => false, 'message' => "Missing Shopee {$modeLabel} credentials."], 422);
        }

        $res = $client->shopGet(
            $auth['mode'],
            (int) $auth['partner_id'],
            (string) $auth['partner_key'],
            (string) $auth['access_token'],
            (int) $auth['shop_id'],
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

        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            if ($json) return response()->json(['ok' => false, 'message' => "Missing Shopee {$modeLabel} credentials/token."], 422);
            return redirect()->route('ext.shopee.orders.index')->with('shopee_orders_last_result', [
                'ok' => false,
                'message' => "Missing Shopee {$modeLabel} credentials/token.",
            ]);
        }

        $order = ShopeeOrder::query()
            ->where('region', $auth['region'] ?? 'ph')
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
            $auth['mode'],
            (int) $auth['partner_id'],
            (string) $auth['partner_key'],
            (string) $auth['access_token'],
            (int) $auth['shop_id'],
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
            $addressList = ($paramResp['pickup']['address_list'] ?? []);
            $addressId = (int) $request->input('address_id', 0);
            $chosenAddress = null;
            if ($addressId === 0) {
                foreach ($addressList as $addr) {
                    if (is_array($addr) && in_array('pickup_address', $addr['address_flag'] ?? [])) {
                        $addressId = (int) ($addr['address_id'] ?? 0);
                        $chosenAddress = $addr;
                        break;
                    }
                }
                if ($addressId === 0 && !empty($addressList) && is_array($addressList[0] ?? null)) {
                    $addressId = (int) ($addressList[0]['address_id'] ?? 0);
                    $chosenAddress = $addressList[0];
                }
            } else {
                foreach ($addressList as $addr) {
                    if (is_array($addr) && (int) ($addr['address_id'] ?? 0) === $addressId) {
                        $chosenAddress = $addr;
                        break;
                    }
                }
            }

            $pickup = ['address_id' => $addressId];

            // Honour info_needed: Shopee tells us per-region which extra
            // fields are required. PH carriers commonly require
            // pickup_time_id; we previously ignored that and Shopee
            // returned "Pickup time is out of range". Pick the
            // operator-supplied slot if any, else the slot flagged
            // `recommended`, else the first slot in the list.
            $pickupNeeds = (array) ($infoList['pickup'] ?? []);
            if (in_array('pickup_time_id', $pickupNeeds, true)) {
                $pickupTimeId = $request->input('pickup_time_id', null);
                if ($pickupTimeId === null || $pickupTimeId === '') {
                    $slots = (array) ($chosenAddress['time_slot_list'] ?? []);
                    foreach ($slots as $slot) {
                        if (is_array($slot) && in_array('recommended', (array) ($slot['flags'] ?? []), true)) {
                            $pickupTimeId = $slot['pickup_time_id'] ?? null;
                            break;
                        }
                    }
                    if ($pickupTimeId === null && !empty($slots) && is_array($slots[0] ?? null)) {
                        $pickupTimeId = $slots[0]['pickup_time_id'] ?? null;
                    }
                }
                if ($pickupTimeId !== null && $pickupTimeId !== '') {
                    // Shopee returns pickup_time_id as a string in the
                    // time_slot_list — keep the same type when sending it back.
                    $pickup['pickup_time_id'] = (string) $pickupTimeId;
                }
            }

            $shipBody['pickup'] = $pickup;
        }

        $shipRes = $client->shopPost(
            $auth['mode'],
            (int) $auth['partner_id'],
            (string) $auth['partner_key'],
            (string) $auth['access_token'],
            (int) $auth['shop_id'],
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
            // The reason, not the code and never the payload (2026-09-03).
            $shipReason = \App\Support\MarketplaceAnswer::plain('Shopee', $shipRes);
            if ($json) return response()->json(['ok' => false, 'message' => 'Ship order failed: ' . $shipReason], 422);
            return redirect()->route('ext.shopee.orders.index')->with('shopee_orders_last_result', [
                'ok' => false,
                'message' => 'Ship order failed: ' . $shipReason,
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
        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return response()->json(['ok' => false, 'message' => "Missing Shopee {$modeLabel} credentials."], 422);
        }

        $res = $client->shopGet(
            $auth['mode'],
            (int) $auth['partner_id'],
            (string) $auth['partner_key'],
            (string) $auth['access_token'],
            (int) $auth['shop_id'],
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
        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return response()->json(['ok' => false, 'message' => "Missing Shopee {$modeLabel} credentials."], 422);
        }

        $res = $client->shopGet(
            $auth['mode'],
            (int) $auth['partner_id'],
            (string) $auth['partner_key'],
            (string) $auth['access_token'],
            (int) $auth['shop_id'],
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
            $auth['mode'],
            (int) $auth['partner_id'],
            (string) $auth['partner_key'],
            (string) $auth['access_token'],
            (int) $auth['shop_id'],
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

        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            if ($json) return response()->json(['ok' => false, 'ready' => false, 'message' => "Missing Shopee {$modeLabel} credentials/token."], 422);
            return redirect()->route('ext.shopee.orders.index')->with('shopee_orders_last_result', [
                'ok' => false,
                'message' => "Missing Shopee {$modeLabel} credentials/token.",
            ]);
        }

        $mode = $auth['mode'];
        $pid = (int) $auth['partner_id'];
        $pkey = (string) $auth['partner_key'];
        $token = (string) $auth['access_token'];
        $shopId = (int) $auth['shop_id'];

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

        $settingRaw = ShopeeSetting::defaultStore();
        $setting = $settingRaw?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        $region = $auth['region'] ?? 'ph';

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

        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return redirect()->route('ext.shopee.orders.index', ['tab' => 'RETURN'])->with('shopee_orders_last_result', [
                'ok' => false,
                'message' => "Missing Shopee {$modeLabel} credentials/token. Please configure Shopee settings first.",
            ]);
        }

        $region = $auth['region'] ?? 'ph';
        $saved = 0;
        $errors = 0;
        $pageNo = 0;
        $pageSize = 50;

        while (true) {
            $res = $client->shopGet(
                $auth['mode'],
                (int) $auth['partner_id'],
                (string) $auth['partner_key'],
                (string) $auth['access_token'],
                (int) $auth['shop_id'],
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
                $msg = \App\Support\MarketplaceAnswer::plain('Shopee', ['ok' => false, 'body' => is_array($body) ? $body : []]);
                return redirect()->route('ext.shopee.orders.index', ['tab' => 'RETURN'])->with('shopee_orders_last_result', [
                    'ok' => false,
                    'message' => 'Shopee Returns API error: ' . $msg,
                ]);
            }

            $body = $res['body'] ?? [];
            $respData = $body['response'] ?? $body;
            // Shopee's get_return_list returns the array under `return` (not `return_list`).
            $returnList = $respData['return'] ?? $respData['return_list'] ?? [];
            if (!is_array($returnList) || empty($returnList)) {
                break;
            }

            foreach ($returnList as $ret) {
                $returnSn = $ret['return_sn'] ?? null;
                if (!$returnSn) continue;

                // Fetch detail for this return
                $detailRes = $client->shopGet(
                    $auth['mode'],
                    (int) $auth['partner_id'],
                    (string) $auth['partner_key'],
                    (string) $auth['access_token'],
                    (int) $auth['shop_id'],
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
