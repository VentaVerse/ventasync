<?php

namespace Extensions\tiktok\Controllers;

use App\Http\Controllers\Controller;
use Extensions\tiktok\Models\TikTokApiLog;
use Extensions\tiktok\Models\TikTokOrder;
use Extensions\tiktok\Models\TikTokOrderProduct;
use Extensions\tiktok\Models\TikTokSetting;
use Extensions\tiktok\Services\TikTok\TikTokClient;
use Extensions\tiktok\Services\TikTokCatalogOrderSync;
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

    // TikTok order status → tab mapping
    private array $tabStatusMap = [
        'UNPAID'           => ['UNPAID'],
        'TO_SHIP'          => ['AWAITING_SHIPMENT', 'AWAITING_COLLECTION'],
        'IN_TRANSIT'       => ['IN_TRANSIT'],
        'DELIVERED'        => ['DELIVERED'],
        'COMPLETED'        => ['COMPLETED'],
        'CANCELLED'        => ['CANCELLED'],
    ];

    public function index(Request $request)
    {
        $filters = $request->validate([
            'order_number' => ['nullable', 'string', 'max:80'],
            'buyer_name'   => ['nullable', 'string', 'max:120'],
        ]);

        $orderNumber = trim((string) ($filters['order_number'] ?? ''));
        $buyerName = trim((string) ($filters['buyer_name'] ?? ''));

        $perPage = (int) $request->query('per_page', 10);
        if (!in_array($perPage, [10, 20, 50], true)) {
            $perPage = 10;
        }

        $tab = strtoupper((string) $request->query('tab', 'TO_SHIP'));
        $allowedTabs = ['ALL', 'UNPAID', 'TO_SHIP', 'IN_TRANSIT', 'DELIVERED', 'COMPLETED', 'CANCELLED'];
        if (!in_array($tab, $allowedTabs, true)) {
            $tab = 'ALL';
        }

        // TO_SHIP has subtabs: to_pack (AWAITING_SHIPMENT) and to_handover (AWAITING_COLLECTION)
        $pendingSubtab = (string) $request->query('pending_sub', $tab === 'TO_SHIP' ? 'to_pack' : '');
        if ($tab !== 'TO_SHIP') {
            $pendingSubtab = '';
        }

        $tabs = [
            'ALL'          => 'All',
            'UNPAID'       => 'Unpaid',
            'TO_SHIP'      => 'To Ship',
            'IN_TRANSIT'   => 'Shipping',
            'DELIVERED'    => 'Delivered',
            'COMPLETED'    => 'Completed',
            'CANCELLED'    => 'Cancelled',
        ];

        $defaultDir = ($tab === 'TO_SHIP') ? 'asc' : 'desc';
        $sortKey = strtolower((string) $request->query('sort', 'created_' . $defaultDir));
        if (!in_array($sortKey, ['created_asc', 'created_desc'], true)) {
            $sortKey = 'created_' . $defaultDir;
        }
        $sortDir = str_ends_with($sortKey, '_asc') ? 'asc' : 'desc';

        $query = TikTokOrder::query()->with('products');
        $baseCountQuery = TikTokOrder::query();

        if ($tab !== 'ALL') {
            // Apply subtab filter for TO_SHIP
            if ($tab === 'TO_SHIP' && $pendingSubtab !== '') {
                $subStatuses = match ($pendingSubtab) {
                    'to_pack'     => ['AWAITING_SHIPMENT'],
                    'to_handover' => ['AWAITING_COLLECTION'],
                    default       => ['AWAITING_SHIPMENT', 'AWAITING_COLLECTION'],
                };
                $query->whereIn('status', $subStatuses);
            } else {
                $statuses = $this->tabStatusMap[$tab] ?? [];
                if (!empty($statuses)) {
                    $query->whereIn('status', $statuses);
                }
            }
        }

        // Search
        if ($orderNumber !== '') {
            $query->where('order_id', 'like', '%' . $orderNumber . '%');
        }
        if ($buyerName !== '') {
            $query->where('buyer_name', 'like', '%' . $buyerName . '%');
        }

        // Badge counts
        $tab_counts = [];
        $tab_counts['ALL'] = (clone $baseCountQuery)->count();
        foreach ($this->tabStatusMap as $k => $statuses) {
            $tab_counts[$k] = (clone $baseCountQuery)->whereIn('status', $statuses)->count();
        }
        // Subtab counts for TO_SHIP
        $tab_counts['to_pack'] = (clone $baseCountQuery)->whereIn('status', ['AWAITING_SHIPMENT'])->count();
        $tab_counts['to_handover'] = (clone $baseCountQuery)->whereIn('status', ['AWAITING_COLLECTION'])->count();

        // Courier tally for To Handover
        $courierCounts = [];
        if ($tab === 'TO_SHIP') {
            $handoverOrders = TikTokOrder::query()
                ->where('status', 'AWAITING_COLLECTION')
                ->get(['id', 'raw']);
            foreach ($handoverOrders as $ho) {
                $hoRaw = is_array($ho->raw) ? $ho->raw : [];
                $courier = (string)($hoRaw['shipping_provider'] ?? $hoRaw['shipping_provider_name'] ?? '');
                if ($courier === '' && !empty($hoRaw['line_items'])) {
                    $courier = (string)(($hoRaw['line_items'][0] ?? [])['shipping_provider_name'] ?? '');
                }
                $label = $courier !== '' ? $courier : 'Unknown';
                $key = mb_strtolower($label);
                $courierCounts[$key] = ['label' => $label, 'count' => ($courierCounts[$key]['count'] ?? 0) + 1];
            }
        }

        $orders = $query
            ->orderBy('order_created_at', $sortDir)
            ->orderBy('created_at', $sortDir)
            ->paginate($perPage)
            ->appends($request->query());

        return view('ext-tiktok::orders.index', [
            'orders'        => $orders,
            'per_page'      => $perPage,
            'tabs'          => $tabs,
            'active_tab'    => $tab,
            'pending_sub'   => $pendingSubtab,
            'tab_counts'    => $tab_counts,
            'sort'          => $sortKey,
            'last_result'   => session('tiktok_orders_last_result'),
            'courier_counts' => $courierCounts,
            'filters'       => [
                'order_number' => $orderNumber,
                'buyer_name'   => $buyerName,
            ],
        ]);
    }

    public function show(Request $request, $id)
    {
        $order = TikTokOrder::with('products')->findOrFail($id);
        $apiError = false;

        if ($request->boolean('refresh')) {
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
