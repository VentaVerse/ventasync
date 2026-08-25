<?php

namespace Extensions\tiktok\Controllers;

use App\Http\Controllers\Controller;
use Extensions\tiktok\Models\TikTokApiLog;
use Extensions\tiktok\Models\TikTokOrder;
use Extensions\tiktok\Models\TikTokOrderProduct;
use Extensions\tiktok\Models\TikTokSetting;
use Extensions\tiktok\Services\TikTok\TikTokClient;
use Extensions\tiktok\Services\TikTokCatalogOrderSync;
use Extensions\tiktok\Services\TikTokOrdersPanel;
use Extensions\tiktok\Models\TikTokReturn;
use Extensions\tiktok\Models\TikTokOrderStatusMap;
use Extensions\tiktok\Services\TikTokReturnsPanel;
use App\Models\Catalog\Order;
use App\Models\Catalog\OrderHistory;
use Illuminate\Http\Request;

class TikTokOrderController extends Controller
{
    private function creds(): array
    {
        $s = TikTokSetting::first();
        if (!$s) {
            abort(404, 'TikTok settings not configured.');
        }
        $d = $s->decrypted();
        $sandbox = $s->mode === 'sandbox';

        return [
            'setting'     => $s,
            'sandbox'     => $sandbox,
            'app_key'     => $sandbox ? ($d->sandbox_app_key ?? '') : ($d->app_key ?? ''),
            'app_secret'  => $sandbox ? ($d->sandbox_app_secret ?? '') : ($d->app_secret ?? ''),
            'token'       => $sandbox ? ($d->sandbox_access_token ?? '') : ($d->access_token ?? ''),
            'shop_cipher' => $sandbox ? ($s->sandbox_shop_cipher ?? '') : ($s->shop_cipher ?? ''),
        ];
    }

    private function logApi(string $method, string $path, array $result): void
    {
        TikTokApiLog::safeCreate([
            'pack'            => 'order-sync',
            'method'          => $method,
            'api_path'        => $path,
            'auth_required'   => true,
            'request_params'  => [],
            'response_status' => $result['status'] ?? 0,
            'ok'              => $result['ok'] ?? false,
            'response_body'   => $result['body'] ?? [],
            'user_id'         => auth()->id(),
        ]);
    }

    /**
     * The list itself is assembled by TikTokOrdersPanel, because the
     * fulfilment hub renders the same panel from the same array. The tab
     * map, the counts, the sort persistence and the courier tally moved
     * there unchanged; nothing about the query or the wire format differs
     * from what this method did inline.
     */
    public function returns(Request $request, TikTokReturnsPanel $panel)
    {
        return view('ext-tiktok::orders.returns', $panel->build($request));
    }

    /**
     * Pull returns and refunds from TikTok Shop (#156): the return_refund
     * search, fifty a page. Each return is stored whole; when its status
     * is mapped on the settings page, the linked sales order takes that
     * status with a history line, the same way an order sync does.
     */
    public function fetchReturns(Request $request, TikTokClient $client)
    {
        try { @set_time_limit(0); } catch (\Throwable $e) {}

        $c = $this->creds();
        if (!$c) {
            return redirect()->route('ext.tiktok.orders.returns')->with('tiktok_returns_last_result', [
                'ok' => false, 'message' => 'Missing TikTok credentials. Configure TikTok settings first.',
            ]);
        }

        $body = [];
        if ($request->input('date_from')) {
            $body['create_time_ge'] = strtotime($request->input('date_from') . ' 00:00:00');
        }
        if ($request->input('date_to')) {
            $body['create_time_lt'] = strtotime($request->input('date_to') . ' 23:59:59');
        }

        $all = [];
        $token = null;
        $failure = null;
        for ($page = 0; $page < 20; $page++) {
            try {
                $res = $client->post($c['app_key'], $c['app_secret'], $c['token'], '/return_refund/202309/returns/search',
                    array_filter(['page_size' => 50, 'page_token' => $token]), $body, $c['shop_cipher'] ?: null);
            } catch (\Throwable $e) {
                $res = ['ok' => false, 'status' => 0, 'body' => ['message' => \App\Support\TransportError::plain($e, 'TikTok Shop')]];
            }
            TikTokApiLog::safeCreate([
                'pack' => 'tiktok.returns.fetch', 'method' => 'POST',
                'api_path' => '/return_refund/202309/returns/search', 'auth_required' => true,
                'request_params' => $body + ['page_token' => $token],
                'response_status' => $res['status'] ?? 0, 'ok' => (bool) ($res['ok'] ?? false),
                'response_body' => $res['body'] ?? [], 'user_id' => auth()->id(),
            ]);
            if (!($res['ok'] ?? false) || (int) ($res['body']['code'] ?? -1) !== 0) {
                $failure = (string) ($res['body']['message'] ?? 'no response');
                break;
            }
            foreach ((array) ($res['body']['data']['return_orders'] ?? []) as $ro) {
                $all[] = $ro;
            }
            $token = $res['body']['data']['next_page_token'] ?? null;
            if (!$token) {
                break;
            }
        }

        if ($failure !== null && empty($all)) {
            return redirect()->route('ext.tiktok.orders.returns')->with('tiktok_returns_last_result', [
                'ok' => false, 'message' => 'TikTok Shop did not answer: ' . $failure,
            ]);
        }

        $returnMap = TikTokOrderStatusMap::where('context', 'return')->pluck('order_status_id', 'tiktok_status')->all();
        $saved = 0;
        $updated = 0;
        $statused = 0;
        foreach ($all as $ro) {
            if (!is_array($ro) || empty($ro['return_id'])) {
                continue;
            }
            $items = [];
            foreach ((array) ($ro['return_line_items'] ?? []) as $li) {
                $items[] = [
                    'seller_sku' => (string) ($li['seller_sku'] ?? ''),
                    'sku_id' => (string) ($li['sku_id'] ?? ''),
                    'product_name' => (string) ($li['product_name'] ?? ''),
                    'sku_name' => (string) ($li['sku_name'] ?? ''),
                    'image' => (string) ($li['product_image']['url'] ?? ''),
                    'refund_amount' => isset($li['refund_amount']['refund_total']) ? (float) $li['refund_amount']['refund_total'] : null,
                    'quantity' => (int) ($li['return_quantity'] ?? 1),
                ];
            }
            $payload = [
                'order_id' => (string) ($ro['order_id'] ?? '') ?: null,
                'return_type' => (string) ($ro['return_type'] ?? '') ?: null,
                'return_status' => (string) ($ro['return_status'] ?? '') ?: null,
                'reason' => \Illuminate\Support\Str::limit((string) ($ro['return_reason_text'] ?? $ro['return_reason'] ?? ''), 250) ?: null,
                'refund_amount' => isset($ro['refund_amount']['refund_total']) ? (float) $ro['refund_amount']['refund_total'] : null,
                'currency' => (string) ($ro['refund_amount']['currency'] ?? '') ?: null,
                'return_created_at' => !empty($ro['create_time']) ? \Illuminate\Support\Carbon::createFromTimestamp((int) $ro['create_time']) : null,
                'return_updated_at' => !empty($ro['update_time']) ? \Illuminate\Support\Carbon::createFromTimestamp((int) $ro['update_time']) : null,
                'tracking_number' => (string) ($ro['return_tracking_number'] ?? '') ?: null,
                'items' => $items,
                'raw' => $ro,
            ];
            $existing = TikTokReturn::query()->where('return_id', (string) $ro['return_id'])->first();
            if ($existing) {
                $existing->fill($payload)->save();
                $updated++;
            } else {
                TikTokReturn::query()->create(['return_id' => (string) $ro['return_id']] + $payload);
                $saved++;
            }

            // The return status map: a mapped status moves the linked sales order.
            $statusId = (int) ($returnMap[(string) ($ro['return_status'] ?? '')] ?? 0);
            $catalogOrderId = $payload['order_id']
                ? (int) (\DB::table('tiktok_orders')->where('order_id', $payload['order_id'])->value('catalog_order_id') ?? 0)
                : 0;
            if ($statusId > 0 && $catalogOrderId > 0) {
                $catalogOrder = Order::find($catalogOrderId);
                if ($catalogOrder && (int) $catalogOrder->order_status_id !== $statusId) {
                    $catalogOrder->update(['order_status_id' => $statusId]);
                    OrderHistory::create([
                        'order_id' => $catalogOrderId, 'order_status_id' => $statusId, 'notify' => 0,
                        'comment' => 'TikTok return ' . $ro['return_id'] . ' (' . (string) ($ro['return_status'] ?? '') . ')',
                        'date_added' => now(), 'user_id' => null, 'user_name' => 'TikTok Sync',
                    ]);
                    $statused++;
                }
            }
        }

        $message = 'Returns synced. ' . $saved . ' new, ' . $updated . ' updated' . ($statused ? ', ' . $statused . ' sales order' . ($statused === 1 ? '' : 's') . ' moved by the return status map' : '') . '.';
        if ($failure !== null) {
            $message .= ' TikTok Shop stopped answering part way: ' . $failure;
        }

        return redirect()->route('ext.tiktok.orders.returns')->with('tiktok_returns_last_result', ['ok' => true, 'message' => $message]);
    }

    public function index(Request $request, TikTokOrdersPanel $panel)
    {
        return view('ext-tiktok::orders.index', $panel->build($request));
    }

    public function show(Request $request, $id)
    {
        $order = TikTokOrder::with('products')->findOrFail($id);
        $apiError = false;

        // Manage-tier only: a view-tier operator (view_tiktok_orders without
        // manage_tiktok_orders) must never trigger a live API pull + write,
        // even by adding `?refresh=1` themselves. They still get the page
        // rendered from stored data.
        $canManage = (bool) $request->user()?->hasPermission('manage_tiktok/order');

        if ($request->boolean('refresh') && $canManage) {
            try {
                $c = $this->creds();
                $client = new TikTokClient();
                $result = $client->getProduct($c['app_key'], $c['app_secret'], $c['token'], $order->order_id, $c['shop_cipher']);

                // Use the order detail endpoint instead
                $detailResult = $client->post(
                    $c['app_key'], $c['app_secret'], $c['token'],
                    '/order/202309/orders',
                    [],
                    ['order_ids' => [$order->order_id]],
                    $c['shop_cipher']
                );

                if (($detailResult['ok'] ?? false) && is_array($detailResult['body'])) {
                    $orders = $detailResult['body']['data']['orders'] ?? [];
                    if (!empty($orders[0])) {
                        $detail = $orders[0];
                        $order->status = $detail['status'] ?? $order->status;
                        $order->raw = $detail;
                        $order->buyer_name = $detail['recipient_address']['name'] ?? ($detail['buyer_name'] ?? $order->buyer_name);
                        $order->save();
                        $order->load('products');
                    }
                } else {
                    $apiError = true;
                }
            } catch (\Throwable $e) {
                $apiError = true;
            }
        }

        return view('ext-tiktok::orders.show', [
            'order' => $order,
            'api_error' => $apiError,
        ]);
    }

    public function fetch(Request $request)
    {
        try { @set_time_limit(0); } catch (\Throwable $e) {}

        $data = $request->validate([
            'date_from' => 'required|date',
            'date_to'   => 'required|date|after_or_equal:date_from',
        ]);

        $c = $this->creds();
        $client = new TikTokClient();

        $tz = new \DateTimeZone('Asia/Manila');
        $from = (new \DateTime($data['date_from'], $tz))->setTime(0, 0, 0)->getTimestamp();
        $to = (new \DateTime($data['date_to'], $tz))->setTime(23, 59, 59)->getTimestamp();

        $allOrders = [];
        $nextToken = '';
        $pageSize = 50;
        $pages = 0;
        $maxPages = 20;

        // Search returns full order details including line items
        while ($pages < $maxPages) {
            $body = [
                'create_time_ge' => $from,
                'create_time_lt' => $to,
            ];
            if ($nextToken !== '') {
                $body['next_page_token'] = $nextToken;
            }

            $result = $client->searchOrders($c['app_key'], $c['app_secret'], $c['token'], $pageSize, $body, $c['shop_cipher']);
            $this->logApi('POST', '/order/202309/orders/search', $result);

            $apiCode = (int) ($result['body']['code'] ?? -1);
            if ($apiCode !== 0) {
                $msg = $result['body']['message'] ?? 'Unknown error';
                return redirect()->route('ext.tiktok.orders.index')
                    ->with('tiktok_orders_last_result', ['ok' => false, 'message' => 'Search orders failed: ' . $msg]);
            }

            $orderList = $result['body']['data']['orders'] ?? [];
            $allOrders = array_merge($allOrders, $orderList);

            $nextToken = $result['body']['data']['next_page_token'] ?? '';
            if ($nextToken === '' || count($orderList) < $pageSize) {
                break;
            }
            $pages++;
        }

        if (empty($allOrders)) {
            return redirect()->route('ext.tiktok.orders.index')
                ->with('tiktok_orders_last_result', ['ok' => true, 'message' => 'No orders found in date range.']);
        }

        $saved = 0;
        $updated = 0;
        $catalogSync = new TikTokCatalogOrderSync();

        foreach ($allOrders as $o) {
            $orderId = (string) ($o['id'] ?? '');
            if ($orderId === '') continue;

            $status = $o['status'] ?? null;
            $createdAt = isset($o['create_time']) ? \Carbon\Carbon::createFromTimestamp((int) $o['create_time']) : null;
            $updatedAt = isset($o['update_time']) ? \Carbon\Carbon::createFromTimestamp((int) $o['update_time']) : null;

            $buyer = $o['recipient_address']['name'] ?? '';

            $existing = TikTokOrder::where('order_id', $orderId)->first();

            $orderData = [
                'region'           => $c['setting']->region ?? 'PH',
                'order_id'         => $orderId,
                'status'           => $status,
                'order_created_at' => $createdAt,
                'order_updated_at' => $updatedAt,
                'raw'              => $o,
                'buyer_name'       => $buyer,
            ];

            if ($existing) {
                $existing->update($orderData);
                $dbOrder = $existing;
                $updated++;
            } else {
                $dbOrder = TikTokOrder::create($orderData);
                $saved++;
            }

            // Sync line items
            $lineItems = $o['line_items'] ?? [];
            $existingItemIds = $dbOrder->products()->pluck('order_line_item_id')->toArray();

            foreach ($lineItems as $li) {
                $lineItemId = (string) ($li['id'] ?? '');
                $itemData = [
                    'tiktok_order_id'   => $dbOrder->id,
                    'order_line_item_id'=> $lineItemId,
                    'sku'               => $li['seller_sku'] ?? $li['sku_id'] ?? '',
                    'name'              => $li['product_name'] ?? '',
                    'variation'         => $li['sku_name'] ?? '',
                    'quantity'          => (int) ($li['quantity'] ?? 1),
                    'item_price'        => (float) ($li['original_price'] ?? 0),
                    'sale_price'        => (float) ($li['sale_price'] ?? 0),
                    'status'            => $li['display_status'] ?? $status,
                    'image'             => $li['sku_image'] ?? $li['product_image'] ?? '',
                    'raw'               => $li,
                ];

                if (in_array($lineItemId, $existingItemIds)) {
                    TikTokOrderProduct::where('tiktok_order_id', $dbOrder->id)
                        ->where('order_line_item_id', $lineItemId)
                        ->update($itemData);
                } else {
                    TikTokOrderProduct::create($itemData);
                }
            }

            // Sync to core ERP catalog order (stock deduction + FCM + history)
            try {
                $dbOrder->refresh();
                $catalogSync->sync($dbOrder);
            } catch (\Throwable $e) {
                \Log::warning('TikTok catalog sync failed for order ' . $orderId . ': ' . $e->getMessage());
            }
        }

        // Update last sync timestamp
        $c['setting']->update(['last_order_sync_at' => now()]);

        return redirect()->route('ext.tiktok.orders.index')
            ->with('tiktok_orders_last_result', [
                'ok' => true,
                'message' => "Fetched " . count($allOrders) . " order(s). Saved: {$saved}, Updated: {$updated}.",
            ]);
    }

    public function updateStatuses(Request $request)
    {
        try { @set_time_limit(0); } catch (\Throwable $e) {}

        $c = $this->creds();
        $client = new TikTokClient();

        // Re-fetch recent orders (last 30 days) to refresh statuses
        $from = now()->subDays(30)->startOfDay()->getTimestamp();
        $to = now()->endOfDay()->getTimestamp();

        $allOrders = [];
        $nextToken = '';
        $pageSize = 50;
        $pages = 0;

        while ($pages < 20) {
            $body = [
                'create_time_ge' => $from,
                'create_time_lt' => $to,
            ];
            if ($nextToken !== '') {
                $body['next_page_token'] = $nextToken;
            }

            $result = $client->searchOrders($c['app_key'], $c['app_secret'], $c['token'], $pageSize, $body, $c['shop_cipher']);
            $this->logApi('POST', '/order/202309/orders/search', $result);

            $apiCode = (int) ($result['body']['code'] ?? -1);
            if ($apiCode !== 0) break;

            $orderList = $result['body']['data']['orders'] ?? [];
            $allOrders = array_merge($allOrders, $orderList);

            $nextToken = $result['body']['data']['next_page_token'] ?? '';
            if ($nextToken === '' || count($orderList) < $pageSize) break;
            $pages++;
        }

        $updated = 0;
        $catalogSync = new TikTokCatalogOrderSync();

        foreach ($allOrders as $o) {
            $orderId = (string) ($o['id'] ?? '');
            if ($orderId === '') continue;

            $dbOrder = TikTokOrder::where('order_id', $orderId)->first();
            if (!$dbOrder) continue;

            $newStatus = $o['status'] ?? $dbOrder->status;
            $updatedAt = isset($o['update_time']) ? \Carbon\Carbon::createFromTimestamp((int) $o['update_time']) : null;
            $buyer = $o['recipient_address']['name'] ?? $dbOrder->buyer_name;

            $dbOrder->update([
                'status'           => $newStatus,
                'order_updated_at' => $updatedAt,
                'raw'              => $o,
                'buyer_name'       => $buyer,
            ]);

            // Update line item statuses
            foreach ($o['line_items'] ?? [] as $li) {
                $lineItemId = (string) ($li['id'] ?? '');
                if ($lineItemId === '') continue;

                TikTokOrderProduct::where('tiktok_order_id', $dbOrder->id)
                    ->where('order_line_item_id', $lineItemId)
                    ->update([
                        'status' => $li['display_status'] ?? $newStatus,
                        'raw'    => $li,
                    ]);
            }

            // Sync to core ERP catalog order (stock deduction + FCM + history)
            try {
                $dbOrder->refresh();
                $catalogSync->sync($dbOrder);
            } catch (\Throwable $e) {
                \Log::warning('TikTok catalog sync failed for order ' . $orderId . ': ' . $e->getMessage());
            }

            $updated++;
        }

        // Update last sync timestamp
        $c['setting']->update(['last_order_sync_at' => now()]);

        return redirect()->route('ext.tiktok.orders.index')
            ->with('tiktok_orders_last_result', [
                'ok'      => true,
                'message' => "Updated {$updated} order(s).",
            ]);
    }

    public function shipOrder(Request $request, $id)
    {
        $order = TikTokOrder::findOrFail($id);
        $c = $this->creds();
        $client = new TikTokClient();

        // Build packages array from order's raw data
        $raw = $order->raw ?? [];
        $rawPackages = $raw['packages'] ?? [];

        if (empty($rawPackages)) {
            return redirect()->back()->with('tiktok_orders_last_result', [
                'ok' => false,
                'message' => 'No packages found for this order.',
            ]);
        }

        $packageData = [
            'packages' => array_map(fn($p) => ['id' => $p['id']], $rawPackages),
        ];

        // Allow optional handover_method from the form
        if ($request->filled('handover_method')) {
            $packageData['handover_method'] = $request->input('handover_method');
        }

        $result = $client->shipPackage($c['app_key'], $c['app_secret'], $c['token'], $order->order_id, $packageData, $c['shop_cipher']);
        $this->logApi('POST', '/fulfillment/202309/packages/ship', $result);

        $apiCode = (int) ($result['body']['code'] ?? -1);
        $msg = $result['body']['message'] ?? 'Unknown error';

        if ($apiCode !== 0) {
            return redirect()->back()->with('tiktok_orders_last_result', [
                'ok' => false,
                'message' => 'Ship order failed: ' . $msg,
            ]);
        }

        // Update local status so order moves to Handover tab immediately
        $order->update(['status' => 'AWAITING_COLLECTION']);

        $awbUrl = route('ext.tiktok.orders.awb', $order->id);

        return redirect()->back()->with('tiktok_orders_last_result', [
            'ok' => true,
            'message' => "Order {$order->order_id} shipped successfully.",
            'awb_url' => $awbUrl,
        ]);
    }

    public function awbPdf(Request $request, $id)
    {
        $order = TikTokOrder::findOrFail($id);
        $safeOrderId = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $order->order_id);
        $awbDir = storage_path('app/tiktok-awb');
        $awbPath = $awbDir . '/' . $safeOrderId . '.pdf';

        // Serve saved AWB if it exists locally (unless refresh requested)
        if (file_exists($awbPath) && !$request->boolean('refresh')) {
            return response(file_get_contents($awbPath), 200)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'inline; filename="AWB-' . $safeOrderId . '.pdf"');
        }

        // Delete cached version if refreshing
        if (file_exists($awbPath) && $request->boolean('refresh')) {
            unlink($awbPath);
        }

        // Try to get package_id from raw data
        $raw = $order->raw ?? [];
        $packages = $raw['packages'] ?? [];
        $packageId = $packages[0]['id'] ?? null;

        if (!$packageId) {
            return redirect()->back()->with('tiktok_orders_last_result', [
                'ok' => false,
                'message' => 'No package found for this order. Ship the order first.',
            ]);
        }

        $c = $this->creds();
        $client = new TikTokClient();

        $documentType = $request->query('type', 'SHIPPING_LABEL');
        $result = $client->getShippingDocument($c['app_key'], $c['app_secret'], $c['token'], $packageId, $documentType, $c['shop_cipher']);
        $this->logApi('GET', '/fulfillment/202309/packages/' . $packageId . '/shipping_documents', $result);

        $apiCode = (int) ($result['body']['code'] ?? -1);
        if ($apiCode !== 0) {
            $msg = $result['body']['message'] ?? 'Unknown error';
            return redirect()->back()->with('tiktok_orders_last_result', [
                'ok' => false,
                'message' => 'Failed to get shipping document: ' . $msg,
            ]);
        }

        $docUrl = $result['body']['data']['doc_url'] ?? ($result['body']['data']['document_url'] ?? null);

        if (!$docUrl) {
            return redirect()->back()->with('tiktok_orders_last_result', [
                'ok' => false,
                'message' => 'No document URL returned by TikTok.',
            ]);
        }

        // Download PDF from TikTok and save locally
        try {
            $pdfContent = \Illuminate\Support\Facades\Http::timeout(15)->get($docUrl)->body();

            if ($pdfContent && str_starts_with($pdfContent, '%PDF')) {
                if (!is_dir($awbDir)) {
                    mkdir($awbDir, 0755, true);
                }
                file_put_contents($awbPath, $pdfContent);

                return response($pdfContent, 200)
                    ->header('Content-Type', 'application/pdf')
                    ->header('Content-Disposition', 'inline; filename="AWB-' . $safeOrderId . '.pdf"');
            }
        } catch (\Throwable $e) {
            // Fall through to redirect if download fails
        }

        // Fallback: redirect to TikTok URL if local save failed
        return redirect($docUrl);
    }

    public function tracking($id)
    {
        $order = TikTokOrder::findOrFail($id);
        $c = $this->creds();
        $client = new TikTokClient();

        $result = $client->getOrderTracking($c['app_key'], $c['app_secret'], $c['token'], $order->order_id, $c['shop_cipher']);
        $this->logApi('GET', '/fulfillment/202309/orders/' . $order->order_id . '/tracking', $result);

        $body = $result['body'] ?? [];
        $apiCode = (int) ($body['code'] ?? -1);
        $ok = $apiCode === 0;

        // Get tracking number and courier from order raw data
        $raw = is_array($order->raw) ? $order->raw : [];
        $trackingNo = (string) ($raw['tracking_number'] ?? '');
        $carrier = (string) ($raw['shipping_provider'] ?? '');
        if (!$carrier && !empty($raw['line_items'])) {
            $carrier = (string) (($raw['line_items'][0] ?? [])['shipping_provider_name'] ?? '');
        }

        return response()->json([
            'ok'               => $ok,
            'body'             => $body,
            'tracking_number'  => $trackingNo,
            'shipping_carrier' => $carrier,
            'message'          => $ok ? null : ($body['message'] ?? 'TikTok API error (code: ' . $apiCode . ')'),
        ]);
    }
}
