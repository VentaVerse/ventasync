<?php

namespace Extensions\tiktok\Controllers;

use App\Http\Controllers\Controller;
use Extensions\tiktok\Models\TikTokApiLog;
use Extensions\tiktok\Models\TikTokOrder;
use Extensions\tiktok\Models\TikTokOrderProduct;
use Extensions\tiktok\Models\TikTokSetting;
use Extensions\tiktok\Services\TikTok\TikTokClient;
use Extensions\tiktok\Services\TikTok\TikTokReturnSync;
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
    use \App\Http\Controllers\Concerns\DrivesOrderFetchRuns;

    private function creds(): array
    {
        $s = TikTokSetting::defaultStore();
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

    public function returns(Request $request, TikTokReturnsPanel $panel)
    {
        return view('ext-tiktok::orders.returns', $panel->build($request));
    }

    public function fetchReturns(Request $request, TikTokReturnSync $sync)
    {
        try { @set_time_limit(0); } catch (\Throwable $e) {}

        $c = $this->creds();
        if (!$c) {
            return redirect()->route('ext.tiktok.orders.returns')->with('tiktok_returns_last_result', [
                'ok' => false, 'message' => 'Missing TikTok credentials. Configure TikTok settings first.',
            ]);
        }

        $from = $request->input('date_from') ? strtotime($request->input('date_from') . ' 00:00:00') : null;
        $to = $request->input('date_to') ? strtotime($request->input('date_to') . ' 23:59:59') : null;

        $result = $sync->sync($c, $from ?: null, $to ?: null, auth()->id());

        return redirect()->route('ext.tiktok.orders.returns')
            ->with('tiktok_returns_last_result', ['ok' => $result['ok'], 'message' => $result['message']]);
    }

    public function index(Request $request, TikTokOrdersPanel $panel)
    {
        return view('ext-tiktok::orders.index', $panel->build($request));
    }

    public function show(Request $request, $id)
    {
        $order = TikTokOrder::with('products')->findOrFail($id);
        $apiError = false;

        $canManage = (bool) $request->user()?->hasPermission('manage_tiktok/order');

        if ($request->boolean('refresh') && $canManage) {
            try {
                $c = $this->creds();
                $client = app(TikTokClient::class);
                $detailResult = $client->getOrderDetail($c['app_key'], $c['app_secret'], $c['token'], [(string) $order->order_id], $c['shop_cipher']);

                $body = is_array($detailResult['body'] ?? null) ? $detailResult['body'] : [];
                $detail = $body['data']['orders'][0] ?? null;
                if (($detailResult['ok'] ?? false) && (int) ($body['code'] ?? -1) === 0 && is_array($detail)) {
                    $order->status = $detail['status'] ?? $order->status;
                    $order->raw = $detail;
                    $order->buyer_name = $detail['recipient_address']['name'] ?? ($detail['buyer_name'] ?? $order->buyer_name);
                    $order->save();
                    $order->load('products');
                } else {
                    $apiError = true;
                }
            } catch (\Throwable $e) {
                $apiError = true;
            }
        }

        return view('ext-tiktok::orders.show', [
            'order' => $order,
            'breakdown' => \Extensions\tiktok\Services\TikTokOrderBreakdown::from(is_array($order->fees) ? $order->fees : []),
            'breakdownCurrency' => (string) ((is_array($order->raw) ? ($order->raw['currency'] ?? null) : null) ?: \App\Support\Money::defaultCode()),
            'api_error' => $apiError,
        ]);
    }

    public function fetch(Request $request)
    {
        $data = $request->validate([
            'date_from' => 'required|date',
            'date_to'   => 'required|date|after_or_equal:date_from',
        ]);

        $runner = app(\App\Services\OrderFetchRunner::class);
        $run = $runner->begin($this->orderFetchIntegration(), $this->orderFetchStoreId($request), $data['date_from'], $data['date_to'], []);
        $run = $runner->walk($run, fn (\App\Models\OrderFetchRun $r) => $this->orderFetchStep($r));

        $message = $run->status === \App\Models\OrderFetchRun::FAILED
            ? (string) $run->last_error
            : \App\Services\OrderFetchRunner::outcome($run) . ($run->status === \App\Models\OrderFetchRun::RUNNING ? ' The page continues the rest.' : '');

        return redirect()->route('ext.tiktok.orders.index')
            ->with('tiktok_orders_last_result', ['ok' => $run->status !== \App\Models\OrderFetchRun::FAILED, 'message' => $message, 'run' => $run->id]);
    }

    protected function orderFetchIntegration(): string
    {
        return 'tiktok';
    }

    protected function orderFetchStoreId(Request $request): ?int
    {
        return \Extensions\tiktok\Models\TikTokSetting::defaultStore()?->id;
    }

    public function orderFetchStep(\App\Models\OrderFetchRun $run): array
    {
        $c = $this->creds();
        if (!$c) {
            return ['error' => 'This store is not connected to TikTok Shop.'];
        }
        $client = new TikTokClient();
        $tz = new \DateTimeZone('Asia/Manila');
        $from = (new \DateTime($run->date_from->format('Y-m-d'), $tz))->setTime(0, 0, 0)->getTimestamp();
        $to = (new \DateTime($run->date_to->format('Y-m-d'), $tz))->setTime(23, 59, 59)->getTimestamp();
        $token = (string) ($run->cursor['token'] ?? '');

        $body = ['create_time_ge' => $from, 'create_time_lt' => $to];
        if ($token !== '') {
            $body['next_page_token'] = $token;
        }
        $result = $client->searchOrders($c['app_key'], $c['app_secret'], $c['token'], 50, $body, $c['shop_cipher']);
        $this->logApi('POST', '/order/202309/orders/search', $result);
        if ((int) ($result['body']['code'] ?? -1) !== 0) {
            return ['error' => 'TikTok Shop did not answer: ' . (string) ($result['body']['message'] ?? 'no answer')];
        }
        $orders = (array) ($result['body']['data']['orders'] ?? []);
        $nextToken = (string) ($result['body']['data']['next_page_token'] ?? '');
        $total = isset($result['body']['data']['total_count']) ? (int) $result['body']['data']['total_count'] : null;

        $created = 0;
        $updated = 0;
        $read = 0;
        $catalogSync = new TikTokCatalogOrderSync();
        foreach ($orders as $o) {
            $orderId = (string) ($o['id'] ?? '');
            if ($orderId === '') continue;
            $read++;
            $status = $o['status'] ?? null;
            $orderData = [
                'region' => $c['setting']->region ?? 'PH',
                'order_id' => $orderId,
                'status' => $status,
                'order_created_at' => isset($o['create_time']) ? \Carbon\Carbon::createFromTimestamp((int) $o['create_time']) : null,
                'order_updated_at' => isset($o['update_time']) ? \Carbon\Carbon::createFromTimestamp((int) $o['update_time']) : null,
                'raw' => $o,
                'buyer_name' => $o['recipient_address']['name'] ?? '',
            ];
            $existing = TikTokOrder::where('order_id', $orderId)->first();
            if ($existing) {
                $existing->update($orderData);
                $dbOrder = $existing;
                $updated++;
            } else {
                $dbOrder = TikTokOrder::create($orderData);
                $created++;
            }
            $existingItemIds = $dbOrder->products()->pluck('order_line_item_id')->toArray();
            foreach ((array) ($o['line_items'] ?? []) as $li) {
                $lineItemId = (string) ($li['id'] ?? '');
                $itemData = [
                    'tiktok_order_id' => $dbOrder->id,
                    'order_line_item_id' => $lineItemId,
                    'sku' => $li['seller_sku'] ?? $li['sku_id'] ?? '',
                    'name' => $li['product_name'] ?? '',
                    'variation' => $li['sku_name'] ?? '',
                    'quantity' => (int) ($li['quantity'] ?? 1),
                    'item_price' => (float) ($li['original_price'] ?? 0),
                    'sale_price' => (float) ($li['sale_price'] ?? 0),
                    'status' => $li['display_status'] ?? $status,
                    'image' => $li['sku_image'] ?? $li['product_image'] ?? '',
                    'raw' => $li,
                ];
                if (in_array($lineItemId, $existingItemIds)) {
                    TikTokOrderProduct::where('tiktok_order_id', $dbOrder->id)->where('order_line_item_id', $lineItemId)->update($itemData);
                } else {
                    TikTokOrderProduct::create($itemData);
                }
            }
            try {
                $dbOrder->refresh();
                $catalogSync->sync($dbOrder);
            } catch (\Throwable $e) {
                \Log::warning('TikTok catalog sync failed for order ' . $orderId . ': ' . $e->getMessage());
            }
        }
        $c['setting']->update(['last_order_sync_at' => now()]);

        $done = $nextToken === '' || $orders === [];
        $answer = ['read' => $read, 'created' => $created, 'updated' => $updated, 'failed' => 0, 'done' => $done, 'cursor' => $done ? null : ['token' => $nextToken]];
        if ($total !== null) {
            $answer['total'] = $total;
            $answer['pages'] = (int) max(1, ceil($total / 50));
        }

        return $answer;
    }

    public function updateStatuses(Request $request)
    {
        try { @set_time_limit(0); } catch (\Throwable $e) {}

        $c = $this->creds();
        $client = new TikTokClient();

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

            try {
                $dbOrder->refresh();
                $catalogSync->sync($dbOrder);
            } catch (\Throwable $e) {
                \Log::warning('TikTok catalog sync failed for order ' . $orderId . ': ' . $e->getMessage());
            }

            $updated++;
        }

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

        $passed = match (strtoupper((string) $order->status)) {
            'AWAITING_COLLECTION', 'IN_TRANSIT', 'DELIVERED', 'COMPLETED' => 'This order is already shipped.',
            'CANCELLED' => 'This order was cancelled.',
            default => null,
        };
        if ($passed !== null) {
            return redirect()->back()->with('tiktok_orders_last_result', [
                'ok' => false,
                'error' => 'already_done',
                'message' => $passed,
            ]);
        }

        $c = $this->creds();
        $client = new TikTokClient();

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

        $order->update(['status' => 'AWAITING_COLLECTION']);
        try {
            $detail = $client->getOrderDetail($c['app_key'], $c['app_secret'], $c['token'], [(string) $order->order_id], $c['shop_cipher']);
            $read = $detail['body']['data']['orders'][0] ?? null;
            if (($detail['ok'] ?? false) && (int) ($detail['body']['code'] ?? -1) === 0 && is_array($read) && !empty($read['status'])) {
                $order->update(['status' => $read['status'], 'raw' => $read]);
            }
            (new TikTokCatalogOrderSync())->sync($order->fresh());
        } catch (\Throwable $e) {
        }

        \App\Services\ActivityLogger::log('updated', 'TikTok Order', null, 'Shipped order #' . $order->order_id);

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
        $awbPath = \App\Support\Fulfilment\Waybills::path('tiktok', (int) $order->tiktok_setting_id, (string) $order->order_id);

        if (file_exists($awbPath) && !$request->boolean('refresh')) {
            return response(file_get_contents($awbPath), 200)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'inline; filename="AWB-' . $safeOrderId . '.pdf"');
        }

        if (file_exists($awbPath) && $request->boolean('refresh')) {
            unlink($awbPath);
        }

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

        try {
            $pdfContent = \Illuminate\Support\Facades\Http::timeout(15)->get($docUrl)->body();

            if ($pdfContent && str_starts_with($pdfContent, '%PDF')) {
                \App\Support\Fulfilment\Waybills::save($awbPath, $pdfContent);

                return response($pdfContent, 200)
                    ->header('Content-Type', 'application/pdf')
                    ->header('Content-Disposition', 'inline; filename="AWB-' . $safeOrderId . '.pdf"');
            }
        } catch (\Throwable $e) {
        }

        return redirect()->back()->with('tiktok_orders_last_result', [
            'ok' => false,
            'message' => 'The waybill could not be downloaded from TikTok. Try again in a moment.',
        ]);
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
