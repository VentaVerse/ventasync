<?php

namespace Extensions\lazada\Commands;

use Extensions\lazada\Models\LazadaApiLog;
use Extensions\lazada\Models\LazadaOrder;
use Extensions\lazada\Models\LazadaOrderProduct;
use Extensions\lazada\Models\LazadaReverseOrder;
use Extensions\lazada\Models\LazadaSetting;
use Extensions\lazada\Services\Lazada\LazadaClient;
use Extensions\lazada\Services\LazadaCatalogOrderSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class LazadaSyncOrders extends Command implements \App\Integrations\Contracts\SteppableAutomation
{
    use \App\Console\Concerns\StepsOutsideArtisan;

    protected $signature = 'lazada:sync-orders
        {--both : Sync orders and returns (default behavior)}
        {--returns : Sync reverse orders (returns/refunds) only}
        {--no-returns : Skip reverse order sync}
        {--days= : Override sync_last_days for this run (passed by the universal scheduler)}
        {--days-returns= : Override sync_last_days_returns for this run}';

    protected $description = 'Sync orders from Lazada (create new + update existing + returns)';

    public function handle(LazadaClient $client): int
    {
        $stores = \Extensions\lazada\Models\LazadaSetting::enabledStores();
        if ($stores->isEmpty()) { $this->error('No enabled Lazada store. Configure Lazada settings first.'); return 1; }
        $worst = 0;
        foreach ($stores as $store) {
            $label = $store->store_name ?: ('#' . $store->id);
            $this->info("=== Lazada store: {$label} ===");
            app()->instance('lazada.route-store', $store);
            try {
                $worst = max($worst, $this->handleStore($client, $store));
            } catch (\Throwable $e) {
                $this->error("Store {$label} failed: " . $e->getMessage());
                \Illuminate\Support\Facades\Log::error('Lazada '.class_basename($this).': store failed', ['store' => $store->id, 'error' => $e->getMessage()]);
                $worst = 1;
            }
        }
        app()->forgetInstance('lazada.route-store');
        return $worst;
    }

    private function handleStore(LazadaClient $client, \Extensions\lazada\Models\LazadaSetting $store): int
    {
        $setting = $store->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
            $this->error('Missing Lazada credentials/token. Configure Lazada settings first.');
            return 1;
        }

        if (!empty($setting->expires_at) && strtotime($setting->expires_at) <= time()) {
            $this->warn('Lazada access token expired (' . $setting->expires_at . '). Refresh it from Lazada Settings.');
            return 1;
        }

        if ($paused = Cache::get('lazada_sync_paused')) {
            $this->warn('Lazada order sync paused due to recent API error (' . $paused . '). Will retry automatically.');
            return 1;
        }

        $returnsOnly = $this->option('returns');
        $skipReturns = $this->option('no-returns');

        if (!$returnsOnly) {
            $this->info('--- Syncing Lazada orders ---');
            $this->syncOrders($client, $setting);
            $store->forceFill(['last_order_sync_at' => now()])->save();

            if (Cache::get('lazada_sync_paused')) {
                return 1;
            }

            $this->info('--- Backfilling missing Lazada fees ---');
            $this->backfillMissingFees($client, $setting);

        }

        if (!$skipReturns) {
            $this->info('--- Syncing reverse orders (returns/refunds) ---');
            $this->syncReturns($client, $setting);
            $store->forceFill(['last_return_sync_at' => now()])->save();
        }

        $this->info('Done.');
        return 0;
    }

    private function syncOrders(LazadaClient $client, object $setting): void
    {
        $tz = new \DateTimeZone('Asia/Manila');
        $pageLimit = 100;
        $maxOrdersPerRun = 2000;

        $cliDays = $this->option('days');
        $syncDays = $cliDays !== null && $cliDays !== ''
            ? max(1, (int) $cliDays)
            : (($setting->sync_last_days ?? null) > 0 ? (int) $setting->sync_last_days : 14);

        $baseParams = ['limit' => $pageLimit];

        $latestUpdated = LazadaOrder::query()
            ->where('region', $setting->region)
            ->max('order_updated_at');

        if ($latestUpdated) {
            $dt = (new \DateTime($latestUpdated, $tz))->modify('-2 hours');
            $minDt = (new \DateTime('now', $tz))->modify("-{$syncDays} days");
            if ($dt < $minDt) {
                $dt = $minDt;
            }
            $baseParams['update_after'] = $dt->format('Y-m-d\TH:i:sP');
            $this->info("  Incremental sync since: " . $dt->format('Y-m-d H:i:s') . " (window: {$syncDays} days)");
        } else {
            $dt = (new \DateTime('now', $tz))->modify("-{$syncDays} days");
            $baseParams['created_after'] = $dt->format('Y-m-d\TH:i:sP');
            $this->info("  Initial backfill: last {$syncDays} days");
        }

        $allOrders = [];
        $offset = 0;

        while (true) {
            $params = $baseParams;
            $params['offset'] = $offset;

            $res = $this->runSignedApiCall($client, $setting, 'GET', '/orders/get', $params, 'lazada.orders.get');

            $body = $res['body'] ?? [];
            $pageOrders = [];
            if (($res['ok'] ?? false) && is_array($body)) {
                $dataNode = $body['data'] ?? $body;
                $pageOrders = $dataNode['orders'] ?? $dataNode['order_list'] ?? $dataNode['orders_list'] ?? $dataNode['data'] ?? [];
                if (!is_array($pageOrders)) $pageOrders = [];
            }

            if (!($res['ok'] ?? false)) {
                $msg = is_array($body) ? ($body['message'] ?? 'API error') : 'API error';
                $this->error('  API error: ' . $msg);
                Cache::put('lazada_sync_paused', $msg, now()->addMinutes(10));
                break;
            }

            $allOrders = array_merge($allOrders, $pageOrders);

            if (count($allOrders) >= $maxOrdersPerRun || count($pageOrders) < $pageLimit) {
                break;
            }

            $offset += $pageLimit;
        }

        $created = 0;
        $updated = 0;
        $errors = 0;

        foreach ($allOrders as $o) {
            if (!is_array($o)) continue;

            $orderId = (string) ($o['order_id'] ?? $o['orderId'] ?? $o['order_number'] ?? $o['orderNumber'] ?? '');
            if ($orderId === '') continue;

            $statusVal = $o['statuses'] ?? $o['status'] ?? $o['order_status'] ?? null;
            if (is_array($statusVal)) $statusVal = $statusVal[0] ?? null;

            $existing = LazadaOrder::query()
                ->where('region', $setting->region)
                ->where('order_id', $orderId)
                ->first();

            if ($existing) {
                $existing->fill([
                    'status'           => is_string($statusVal) ? $statusVal : null,
                    'order_created_at' => $this->parseApiDatetime($o['created_at'] ?? $o['createdAt'] ?? $o['created_time'] ?? null),
                    'order_updated_at' => $this->parseApiDatetime($o['updated_at'] ?? $o['updatedAt'] ?? $o['update_time'] ?? null),
                    'raw'              => $o,
                ])->save();
                $updated++;

                $needsProductSync = !$existing->products()->exists();
                if (!$needsProductSync) {
                    $firstProduct = $existing->products()->first();
                    $fpRaw = $firstProduct ? ($firstProduct->raw ?? []) : [];
                    $needsProductSync = trim((string)($fpRaw['shipment_provider'] ?? '')) === ''
                        || trim((string)($fpRaw['tracking_code'] ?? '')) === '';
                }
                if ($needsProductSync) {
                    try {
                        $this->syncOrderProducts($client, $setting, $existing);
                    } catch (\Throwable $e) {
                        Log::warning('Lazada sync: failed to fetch items for order ' . $orderId, ['error' => $e->getMessage()]);
                    }
                }

                try {
                    (new LazadaCatalogOrderSync)->sync($existing);
                } catch (\Throwable $e) {
                    Log::warning('Lazada sync: catalog sync failed for order ' . $orderId, ['error' => $e->getMessage()]);
                }

                $this->fetchFeesIfReady($client, $setting, $existing);
            } else {
                try {
                    $newOrder = LazadaOrder::query()->create([
                        'region'           => $setting->region,
                        'order_id'         => $orderId,
                        'status'           => is_string($statusVal) ? $statusVal : null,
                        'order_created_at' => $this->parseApiDatetime($o['created_at'] ?? $o['createdAt'] ?? $o['created_time'] ?? null),
                        'order_updated_at' => $this->parseApiDatetime($o['updated_at'] ?? $o['updatedAt'] ?? $o['update_time'] ?? null),
                        'raw'              => $o,
                    ]);
                    $created++;

                    try {
                        $this->syncOrderProducts($client, $setting, $newOrder);
                    } catch (\Throwable $e) {
                        Log::warning('Lazada sync: failed to fetch items for order ' . $orderId, ['error' => $e->getMessage()]);
                    }

                    try {
                        (new LazadaCatalogOrderSync)->sync($newOrder);
                    } catch (\Throwable $e) {
                        Log::warning('Lazada sync: failed to sync order ' . $orderId . ' to catalog', ['error' => $e->getMessage()]);
                    }

                    $this->fetchFeesIfReady($client, $setting, $newOrder);
                } catch (\Throwable $e) {
                    $errors++;
                    Log::error('Lazada sync: failed to create order ' . $orderId, ['error' => $e->getMessage()]);
                }
            }
        }

        $this->info("  Synced: {$created} new, {$updated} updated" . ($errors > 0 ? ", {$errors} errors" : ''));
    }

    private function syncReturns(LazadaClient $client, object $setting): void
    {
        $cliDaysReturns = $this->option('days-returns');
        $syncDays = $cliDaysReturns !== null && $cliDaysReturns !== ''
            ? max(1, (int) $cliDaysReturns)
            : (($setting->sync_last_days_returns ?? null) > 0 ? (int) $setting->sync_last_days_returns : 14);
        $tz = new \DateTimeZone('Asia/Manila');
        $dt = (new \DateTime('now', $tz))->modify("-{$syncDays} days");
        $this->info("  Returns window: last {$syncDays} days (since " . $dt->format('Y-m-d H:i:s') . ")");

        $pageSize = 50;
        $allOrders = [];
        $res = null;

        for ($pageNo = 1; $pageNo <= 20; $pageNo++) {
            $res = $this->runSignedApiCall($client, $setting, 'GET', '/reverse/getreverseordersforseller', [
                'page_no' => $pageNo,
                'page_size' => $pageSize,
                'create_time_start' => $dt->format('Y-m-d H:i:s'),
            ], 'lazada.reverse.list');

            $body = $res['body'] ?? [];
            $pageOrders = [];
            if (($res['ok'] ?? false) && is_array($body)) {
                $resultNode = $body['result'] ?? $body['data'] ?? $body;
                $pageOrders = $resultNode['items'] ?? $resultNode['list'] ?? $resultNode['reverse_order_list'] ?? $resultNode['data'] ?? [];
                if (!is_array($pageOrders)) $pageOrders = [];
            }

            if (!($res['ok'] ?? false) || (isset($body['code']) && $body['code'] !== '0' && $body['code'] !== 0)) {
                $msg = is_array($body) ? ($body['message'] ?? 'API error') : 'API error';
                $this->error('  API error: ' . $msg);
                Cache::put('lazada_sync_paused', $msg, now()->addMinutes(10));
                break;
            }

            $allOrders = array_merge($allOrders, $pageOrders);

            if (count($pageOrders) < $pageSize) {
                break;
            }
        }

        $saved = 0;
        $updated = 0;

        foreach ($allOrders as $o) {
            if (!is_array($o)) continue;

            $reverseOrderId = (string)($o['reverse_order_id'] ?? $o['reverseOrderId'] ?? '');
            if ($reverseOrderId === '') continue;

            $tradeOrderId = (string)($o['trade_order_id'] ?? $o['tradeOrderId'] ?? '');
            $lines = $o['reverse_order_lines'] ?? $o['items'] ?? $o['reverse_order_items'] ?? [];
            $firstLine = is_array($lines) && !empty($lines) ? $lines[0] : [];

            $reverseStatus = (string)($o['reverse_status'] ?? $firstLine['reverse_status'] ?? $o['reverseStatus'] ?? $o['status'] ?? '');
            $reverseType = (string)($o['reverse_type'] ?? $o['request_type'] ?? $o['reverseType'] ?? $o['type'] ?? '');
            $reason = (string)($o['reason'] ?? $firstLine['reason_text'] ?? $o['reverse_reason'] ?? '');
            $refundAmount = $o['refund_amount'] ?? $firstLine['refund_amount'] ?? $o['refundAmount'] ?? $o['actual_refund_amount'] ?? null;
            if (is_numeric($refundAmount)) {
                $refundAmount = (float) $refundAmount / 100;
            }
            $currency = (string)($o['currency'] ?? 'PHP');
            $items = is_array($lines) && !empty($lines) ? $lines : null;

            $payload = [
                'region' => $setting->region,
                'reverse_order_id' => $reverseOrderId,
                'trade_order_id' => $tradeOrderId !== '' ? $tradeOrderId : null,
                'reverse_status' => $reverseStatus !== '' ? $reverseStatus : null,
                'reverse_type' => $reverseType !== '' ? $reverseType : null,
                'reason' => $reason !== '' ? $reason : null,
                'refund_amount' => is_numeric($refundAmount) ? $refundAmount : null,
                'currency' => $currency !== '' ? $currency : null,
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
                try {
                    LazadaReverseOrder::query()->create($payload);
                    $saved++;
                } catch (\Throwable $e) {
                    Log::error('Lazada sync: failed to create reverse order ' . $reverseOrderId, ['error' => $e->getMessage()]);
                }
            }
        }

        $this->info("  Returns: {$saved} new, {$updated} updated");
    }

    private function syncOrderProducts(LazadaClient $client, object $setting, LazadaOrder $order): void
    {
        $oid = (string) ($order->order_id ?? '');
        if ($oid === '') return;

        $res = $this->runSignedApiCall($client, $setting, 'GET', '/order/items/get', ['order_id' => $oid], 'lazada.order.items.get');

        if (!($res['ok'] ?? false)) return;

        $itemsBody = $res['body'] ?? [];
        $itemsDataNode = is_array($itemsBody) ? ($itemsBody['data'] ?? $itemsBody) : [];
        if (is_array($itemsDataNode) && array_is_list($itemsDataNode)) {
            $items = $itemsDataNode;
        } else {
            $items = $itemsDataNode['order_items'] ?? $itemsDataNode['items'] ?? $itemsDataNode['data'] ?? [];
        }
        if (!is_array($items)) $items = [];

        foreach ($items as $it) {
            if (!is_array($it)) continue;

            $orderItemId = (string) ($it['order_item_id'] ?? $it['orderItemId'] ?? $it['id'] ?? '');
            if ($orderItemId === '') continue;

            $sellerSku = (string) ($it['seller_sku'] ?? $it['SellerSku'] ?? $it['sellerSku'] ?? $it['sku'] ?? $it['Sku'] ?? '');
            $name = (string) ($it['name'] ?? $it['product_name'] ?? $it['item_name'] ?? '');

            $variation = null;
            foreach (['variation', 'sku_variant', 'variation_sku', 'variation_name', 'variation_detail', 'item_variation'] as $k) {
                if (isset($it[$k]) && is_string($it[$k]) && trim($it[$k]) !== '') {
                    $variation = trim($it[$k]);
                    break;
                }
            }

            $qty = (int) ($it['quantity'] ?? $it['qty'] ?? $it['item_quantity'] ?? 1);
            $image = (string) ($it['product_main_image'] ?? $it['image'] ?? $it['item_image'] ?? $it['sku_image'] ?? '');

            $itemPrice = isset($it['item_price']) && is_numeric($it['item_price']) ? (float) $it['item_price'] : null;
            $paidPrice = isset($it['paid_price']) && is_numeric($it['paid_price']) ? (float) $it['paid_price'] : null;

            LazadaOrderProduct::query()->updateOrCreate(
                [
                    'lazada_order_id' => $order->id,
                    'order_item_id'   => $orderItemId,
                ],
                [
                    'sku'        => $sellerSku !== '' ? $sellerSku : null,
                    'name'       => $name !== '' ? $name : null,
                    'variation'  => $variation,
                    'quantity'   => $qty,
                    'item_price' => $itemPrice,
                    'paid_price' => $paidPrice,
                    'image'      => $image !== '' ? $image : null,
                    'status'     => isset($it['status']) && is_string($it['status']) ? $it['status'] : null,
                    'raw'        => $it,
                ]
            );
        }
    }

    private function runSignedApiCall(LazadaClient $client, object $setting, string $method, string $apiPath, array $customParams, ?string $pack = null): array
    {
        $creds = LazadaSetting::activeCredentials($setting);

        $apiPath = trim($apiPath);
        if (!str_starts_with($apiPath, '/')) $apiPath = '/' . $apiPath;

        $timestamp = (string) round(microtime(true) * 1000);
        $params = [
            'app_key'     => (string) $creds['app_key'],
            'sign_method' => 'sha256',
            'timestamp'   => $timestamp,
            'access_token' => (string) $creds['access_token'],
        ];

        foreach ($customParams as $k => $v) {
            if (!is_string($k) || $k === '' || in_array($k, ['sign', 'app_key', 'sign_method', 'timestamp'], true)) continue;
            $params[$k] = is_scalar($v) || $v === null ? $v : json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $method = strtoupper($method);
        $rateKey = 'lazada_api_last_call:' . $setting->region . ':' . $creds['app_key'];
        $lockKey = 'lazada_api_lock:' . $setting->region . ':' . $creds['app_key'];
        $minIntervalMs = 1200;

        $callOnce = function () use ($client, $setting, $apiPath, &$params, $method, $creds) {
            $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);
            return $method === 'POST'
                ? $client->post($setting->region, $apiPath, $params)
                : $client->get($setting->region, $apiPath, $params);
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
                if ($waitMs > 0) usleep($waitMs * 1000);

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
                usleep((1300 + ($attempt - 1) * 350) * 1000);
                continue;
            }

            break;
        }

        if ($result === null) {
            $result = ['status' => 0, 'ok' => false, 'body' => ['message' => 'API call failed']];
        }

        LazadaApiLog::safeCreate([
            'pack'            => $pack,
            'method'          => $method,
            'api_path'        => $apiPath,
            'auth_required'   => true,
            'request_params'  => $params,
            'response_status' => (int) ($result['status'] ?? 0),
            'ok'              => (bool) ($result['ok'] ?? false),
            'response_body'   => $result['body'] ?? null,
            'user_id'         => null,
        ]);

        return $result;
    }

    private function fetchFeesIfReady(LazadaClient $client, object $setting, LazadaOrder $order): void
    {
        $status = strtolower(trim((string) $order->status));

        if (in_array($status, ['canceled', 'unpaid'])) return;

        $existingFees = $order->fees;
        if (is_array($existingFees) && isset($existingFees['commission'])) return;

        $raw = $order->raw;
        if (is_array($raw) && !empty($raw['price'])) {
            $m = fn ($v) => \App\Support\Money::parse($v);
            $fees = is_array($existingFees) ? $existingFees : [];
            $fees['subtotal'] = $m($raw['price'] ?? 0);
            $fees['paid_total'] = $m($raw['price'] ?? 0)
                - $m($raw['voucher_seller'] ?? 0)
                - $m($raw['voucher_platform'] ?? $raw['voucher'] ?? 0);
            $fees['shipping'] = $m($raw['shipping_fee'] ?? 0);
            $fees['voucher_seller'] = $m($raw['voucher_seller'] ?? 0);
            $fees['voucher_platform'] = $m($raw['voucher_platform'] ?? $raw['voucher'] ?? 0);
            $fees['shipping_discount_seller'] = $m($raw['shipping_fee_discount_seller'] ?? 0);
            $fees['shipping_discount_platform'] = $m($raw['shipping_fee_discount_platform'] ?? 0);
            $fees['wallet_credits'] = $m($raw['wallet_credits'] ?? 0);
            $fees['shipping_service_cost'] = $m($raw['shipping_service_cost'] ?? 0);
            $fees['order_price'] = $m($raw['price'] ?? 0);
            $fees['order_voucher'] = $m($raw['voucher_platform'] ?? $raw['voucher'] ?? 0);

            $order->fees = $fees;
            $order->save();
            $existingFees = $fees;
        }

        try {
            $from = $order->order_created_at ? $order->order_created_at->copy()->subDay() : now()->subDays(170);
            $to = $from->copy()->addDays(171)->min(now()->addDay());
            $trxRes = $this->runSignedApiCall($client, $setting, 'GET', '/finance/transaction/details/get', [
                'trade_order_id' => (string) $order->order_id,
                'start_time'     => $from->format('Y-m-d'),
                'end_time'       => $to->format('Y-m-d'),
            ], 'lazada.finance.transaction_details');

            if (!($trxRes['ok'] ?? false) || !is_array($trxRes['body'] ?? null)) {
                $fees = is_array($existingFees) ? $existingFees : [];
                $fees['_finance_raw'] = $trxRes['body'] ?? null;
                $order->fees = $fees;
                $order->save();
                return;
            }

            $trxData = $trxRes['body']['data'] ?? $trxRes['body'];
            $trxItems = [];
            if (is_array($trxData)) {
                $trxItems = array_is_list($trxData) ? $trxData : ($trxData['data'] ?? $trxData['items'] ?? $trxData['transaction_details'] ?? []);
            }
            if (!is_array($trxItems) || empty($trxItems)) return;

            $commissionTypes = [16, 15, 65, 66, 123, 274, 275, 277, 278, 341];
            $paymentTypes = [3, 4, 67, 84, 514];
            $shippingTypes = [7, 8, 21, 26, 27, 28, 34, 35, 42, 43, 49, 52, 53, 141, 157, 158, 159, 160, 161, 200, 211, 500, 501, 502, 503, 504, 505];

            $commissionTotal = 0;
            $paymentFeeTotal = 0;
            $shippingFeeTotal = 0;
            $otherFees = [];
            $byName = [];

            foreach ($trxItems as $trx) {
                if (!is_array($trx)) continue;

                $feeType = (int) ($trx['fee_type'] ?? 0);
                $feeName = (string) ($trx['fee_name'] ?? $trx['transaction_type'] ?? '');
                // Money::parse strips the thousands comma Lazada sends; a bare float cast reads -2,251.57 as -2.
                $amount = \App\Support\Money::parse($trx['amount'] ?? $trx['fee_amount'] ?? 0);
                $lineName = $feeName !== '' ? $feeName : 'Fee type ' . $feeType;
                $byName[$lineName] = ($byName[$lineName] ?? 0) + $amount;

                if ($feeType && in_array($feeType, $commissionTypes)) {
                    $commissionTotal += $amount;
                } elseif ($feeType && in_array($feeType, $paymentTypes)) {
                    $paymentFeeTotal += $amount;
                } elseif ($feeType && in_array($feeType, $shippingTypes)) {
                    $shippingFeeTotal += $amount;
                } else {
                    $feeNameLower = strtolower($feeName);
                    if (str_contains($feeNameLower, 'commission')) {
                        $commissionTotal += $amount;
                    } elseif (str_contains($feeNameLower, 'payment')) {
                        $paymentFeeTotal += $amount;
                    } elseif (str_contains($feeNameLower, 'shipping') || str_contains($feeNameLower, 'delivery')) {
                        $shippingFeeTotal += $amount;
                    } elseif ($amount != 0) {
                        $label = $feeName !== '' ? $feeName : 'fee_type_' . $feeType;
                        $otherFees[$label] = ($otherFees[$label] ?? 0) + $amount;
                    }
                }
            }

            $fees = is_array($existingFees) ? $existingFees : [];
            if ($commissionTotal != 0) $fees['commission'] = round($commissionTotal, 2);
            if ($paymentFeeTotal != 0) $fees['payment_fee'] = round($paymentFeeTotal, 2);
            if ($shippingFeeTotal != 0) $fees['shipping_service_cost'] = round($shippingFeeTotal, 2);
            if (!empty($otherFees)) $fees['other_fees'] = $otherFees;
            $fees['transactions'] = array_map(
                fn ($name, $total) => ['name' => (string) $name, 'amount' => round($total, 2)],
                array_keys($byName),
                array_values($byName),
            );

            $order->fees = $fees;
            $order->save();
        } catch (\Throwable $e) {
        }
    }

    private function backfillMissingFees(LazadaClient $client, object $setting): void
    {
        $region = $setting->region ?? 'ph';

        $orders = LazadaOrder::query()
            ->where('region', $region)
            ->whereNotIn('status', ['canceled', 'cancelled'])
            ->where(function ($q) {
                $q->whereNull('fees')
                  ->orWhereRaw("JSON_EXTRACT(fees, '$.subtotal') IS NULL");
            })
            ->get();

        if ($orders->isEmpty()) {
            $this->info('  No orders need fee backfill.');
            return;
        }

        $filled = 0;
        foreach ($orders as $order) {
            $before = $order->fees;
            $this->fetchFeesIfReady($client, $setting, $order);
            $after = $order->fresh()->fees;
            if (!empty($after) && $after !== $before) {
                $filled++;
            }
            usleep(300000); // Wait 300ms between calls to stay under Lazada's rate limit.
        }

        $this->info("  Backfilled fees for {$filled}/{$orders->count()} orders.");
    }
    private function parseApiDatetime($value): ?string
    {
        if ($value === null) return null;

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $num = (int) $value;
            if ($num > 1000000000000) $num = (int) floor($num / 1000);
            if ($num > 0) return date('Y-m-d H:i:s', $num);
            return null;
        }

        $str = trim((string) $value);
        if ($str === '') return null;

        $ts = strtotime($str);
        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }

    public function automationUnits(\App\Models\ScheduledJob $job): array
    {
        $args = $job->commandArguments();
        $returnsOnly = ! empty($args['--returns']);
        $skipReturns = ! empty($args['--no-returns']);
        $units = [];
        foreach (\Extensions\lazada\Models\LazadaSetting::enabledStores() as $store) {
            foreach (array_values(array_filter(['orders', 'fees', 'returns'], fn ($p) => $returnsOnly ? $p === 'returns' : ($p !== 'returns' || ! $skipReturns))) as $phase) {
                $units[] = $store->id . ':' . $phase;
            }
        }

        return ['label' => 'steps', 'units' => $units];
    }

    public function automationChunk(): int
    {
        return 1;
    }

    public function automationStep(\App\Models\ScheduledJob $job, array $units): array
    {
        $this->prepareForStep($job->commandArguments());
        $client = app(LazadaClient::class);
        $ok = 0;
        $failed = 0;
        $notes = [];
        foreach ($units as $unit) {
            [$sid, $phase] = array_pad(explode(':', (string) $unit, 2), 2, '');
            $store = \Extensions\lazada\Models\LazadaSetting::query()->find((int) $sid);
            if (! $store) {
                $failed++;
                continue;
            }
            app()->instance('lazada.route-store', $store);
            try {
                $setting = $store->decrypted();
                $creds = LazadaSetting::activeCredentials($setting);
                if (! $setting || ! $setting->region || ! $creds['app_key'] || ! $creds['app_secret'] || ! $creds['access_token']) {
                    return ['error' => 'Store ' . ($store->store_name ?: '#' . $store->id) . ' is not connected to Lazada.'];
                }
                if ($paused = Cache::get('lazada_sync_paused')) {
                    return ['error' => 'Lazada sync is paused after a recent error: ' . $paused . '. It retries on its own in a few minutes.'];
                }
                match ($phase) {
                    'orders' => (function () use ($client, $setting, $store) { $this->syncOrders($client, $setting); $store->forceFill(['last_order_sync_at' => now()])->save(); })(),
                    'fees' => $this->backfillMissingFees($client, $setting),
                    'returns' => (function () use ($client, $setting, $store) { $this->syncReturns($client, $setting); $store->forceFill(['last_return_sync_at' => now()])->save(); })(),
                    default => null,
                };
                if ($paused = Cache::get('lazada_sync_paused')) {
                    return ['error' => 'Lazada refused during ' . $phase . ': ' . $paused];
                }
                $ok++;
            } catch (\Throwable $e) {
                $failed++;
                $notes[] = $phase . ': ' . \App\Support\TransportError::plain($e, 'Lazada');
            } finally {
                app()->forgetInstance('lazada.route-store');
            }
        }

        return ['ok' => $ok, 'failed' => $failed, 'note' => implode('; ', array_slice($notes, 0, 2))];
    }
}
