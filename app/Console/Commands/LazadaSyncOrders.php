<?php

namespace App\Console\Commands;

use App\Models\LazadaApiLog;
use App\Models\LazadaOrder;
use App\Models\LazadaOrderProduct;
use App\Models\LazadaReverseOrder;
use App\Models\LazadaSetting;
use App\Services\Lazada\LazadaClient;
use App\Services\LazadaCatalogOrderSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class LazadaSyncOrders extends Command
{
    protected $signature = 'lazada:sync-orders
        {--fetch : Fetch new orders (default behaviour)}
        {--update : Update statuses of existing orders only}
        {--returns : Sync reverse orders (returns/refunds) only}
        {--both : Fetch new orders AND update existing statuses AND sync returns}';

    protected $description = 'Sync orders from Lazada (fetch new + update statuses + returns)';

    public function handle(LazadaClient $client): int
    {
        $setting = LazadaSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret || !$setting->access_token) {
            $this->error('Missing Lazada credentials/token. Configure Lazada settings first.');
            return 1;
        }

        if (!empty($setting->expires_at) && strtotime($setting->expires_at) <= time()) {
            $this->warn('Lazada access token expired (' . $setting->expires_at . '). Refresh it from Lazada Settings.');
            return 1;
        }

        $hasAnyFlag = $this->option('fetch') || $this->option('update') || $this->option('returns') || $this->option('both');
        $doFetch = $this->option('fetch') || $this->option('both') || !$hasAnyFlag;
        $doUpdate = $this->option('update') || $this->option('both');
        $doReturns = $this->option('returns') || $this->option('both') || !$hasAnyFlag;

        if ($doFetch) {
            $this->info('--- Fetching new Lazada orders ---');
            $this->fetchOrders($client, $setting);
        }

        if ($doUpdate) {
            $this->info('--- Updating statuses of existing orders ---');
            $this->updateStatuses($client, $setting);
        }

        if ($doReturns) {
            $this->info('--- Syncing reverse orders (returns/refunds) ---');
            $this->syncReturns($client, $setting);
        }

        // Stamp sync timestamps on settings (independent of API logging)
        if ($doFetch || $doUpdate) {
            LazadaSetting::query()->update(['last_order_sync_at' => now()]);
        }
        if ($doReturns) {
            LazadaSetting::query()->update(['last_return_sync_at' => now()]);
        }

        $this->info('Done.');
        return 0;
    }

    private function fetchOrders(LazadaClient $client, object $setting): void
    {
        $tz = new \DateTimeZone('Asia/Manila');
        $pageLimit = 100;
        $maxOrdersPerRun = 2000;

        $syncDays = ($setting->sync_last_days ?? null) > 0 ? (int) $setting->sync_last_days : 14;

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
            $this->info("  Incremental fetch since: " . $dt->format('Y-m-d H:i:s') . " (window: {$syncDays} days)");
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
                break;
            }

            $allOrders = array_merge($allOrders, $pageOrders);

            if (count($allOrders) >= $maxOrdersPerRun || count($pageOrders) < $pageLimit) {
                break;
            }

            $offset += $pageLimit;
        }

        $saved = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($allOrders as $o) {
            if (!is_array($o)) continue;

            $orderId = (string) ($o['order_id'] ?? $o['orderId'] ?? $o['order_number'] ?? $o['orderNumber'] ?? '');
            if ($orderId === '') continue;

            $existing = LazadaOrder::query()
                ->where('region', $setting->region)
                ->where('order_id', $orderId)
                ->first();

            if ($existing) {
                $skipped++;
                continue;
            }

            $statusVal = $o['statuses'] ?? $o['status'] ?? $o['order_status'] ?? null;
            if (is_array($statusVal)) $statusVal = $statusVal[0] ?? null;

            $payload = [
                'region'           => $setting->region,
                'order_id'         => $orderId,
                'status'           => is_string($statusVal) ? $statusVal : null,
                'order_created_at' => $this->parseApiDatetime($o['created_at'] ?? $o['createdAt'] ?? $o['created_time'] ?? null),
                'order_updated_at' => $this->parseApiDatetime($o['updated_at'] ?? $o['updatedAt'] ?? $o['update_time'] ?? null),
                'raw'              => $o,
            ];

            try {
                $newOrder = LazadaOrder::query()->create($payload);
                $saved++;

                // Fetch order items
                try {
                    $this->syncOrderProducts($client, $setting, $newOrder);
                } catch (\Throwable $e) {
                    Log::warning('Lazada sync: failed to fetch items for order ' . $orderId, ['error' => $e->getMessage()]);
                }

                // Sync to catalog tables (triggers stock adjustment)
                try {
                    (new LazadaCatalogOrderSync)->sync($newOrder);
                } catch (\Throwable $e) {
                    Log::warning('Lazada sync: failed to sync order ' . $orderId . ' to catalog', ['error' => $e->getMessage()]);
                }
            } catch (\Throwable $e) {
                $errors++;
                Log::error('Lazada sync: failed to create order ' . $orderId, ['error' => $e->getMessage()]);
            }
        }

        $this->info("  Fetched: {$saved} new, {$skipped} skipped" . ($errors > 0 ? ", {$errors} errors" : ''));
    }

    private function updateStatuses(LazadaClient $client, object $setting): void
    {
        $tz = new \DateTimeZone('Asia/Manila');
        $syncDays = ($setting->sync_last_days ?? null) > 0 ? (int) $setting->sync_last_days : 14;
        $dt = (new \DateTime('now', $tz))->modify("-{$syncDays} days");

        $baseParams = [
            'limit'        => 50,
            'update_after' => $dt->format('Y-m-d\TH:i:sP'),
        ];

        $allOrders = [];
        $res = null;

        for ($offset = 0; $offset <= 450; $offset += 50) {
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
                break;
            }

            $allOrders = array_merge($allOrders, $pageOrders);
            if (count($pageOrders) < 50) break;
        }

        $updated = 0;
        $skipped = 0;

        foreach ($allOrders as $o) {
            if (!is_array($o)) continue;

            $orderId = (string) ($o['order_id'] ?? $o['orderId'] ?? $o['order_number'] ?? $o['orderNumber'] ?? '');
            if ($orderId === '') continue;

            $existing = LazadaOrder::query()
                ->where('region', $setting->region)
                ->where('order_id', $orderId)
                ->first();

            if (!$existing) {
                $skipped++;
                continue;
            }

            $statusVal = $o['statuses'] ?? $o['status'] ?? $o['order_status'] ?? null;
            if (is_array($statusVal)) $statusVal = $statusVal[0] ?? null;

            $existing->fill([
                'status'           => is_string($statusVal) ? $statusVal : null,
                'order_created_at' => $this->parseApiDatetime($o['created_at'] ?? $o['createdAt'] ?? $o['created_time'] ?? null),
                'order_updated_at' => $this->parseApiDatetime($o['updated_at'] ?? $o['updatedAt'] ?? $o['update_time'] ?? null),
                'raw'              => $o,
            ])->save();

            $updated++;

            try {
                (new LazadaCatalogOrderSync)->sync($existing);
            } catch (\Throwable $e) {
                Log::warning('Lazada status update: catalog sync failed for order ' . $orderId, ['error' => $e->getMessage()]);
            }
        }

        $this->info("  Updated: {$updated}, skipped: {$skipped} (not yet fetched)");
    }

    /**
     * Sync reverse orders (returns/refunds) from Lazada API.
     */
    private function syncReturns(LazadaClient $client, object $setting): void
    {
        $syncDays = ($setting->sync_last_days_returns ?? null) > 0 ? (int) $setting->sync_last_days_returns : 14;
        $tz = new \DateTimeZone('Asia/Manila');
        $dt = (new \DateTime('now', $tz))->modify("-{$syncDays} days");
        $this->info("  Returns window: last {$syncDays} days (since " . $dt->format('Y-m-d H:i:s') . ")");

        $pageSize = 50;
        $allOrders = [];
        $res = null;

        for ($pageNo = 1; $pageNo <= 20; $pageNo++) {
            $res = $this->runSignedApiCall($client, $setting, 'GET', '/reverse/getreverseordersforseller', [
                'pageNo' => $pageNo,
                'pageSize' => $pageSize,
                'create_time_start' => $dt->format('Y-m-d H:i:s'),
            ], 'lazada.reverse.list');

            $body = $res['body'] ?? [];
            $pageOrders = [];
            if (($res['ok'] ?? false) && is_array($body)) {
                $dataNode = $body['data'] ?? $body;
                $pageOrders = $dataNode['list'] ?? $dataNode['reverse_order_list'] ?? $dataNode['data'] ?? [];
                if (!is_array($pageOrders)) $pageOrders = [];
            }

            if (!($res['ok'] ?? false)) {
                $msg = is_array($body) ? ($body['message'] ?? 'API error') : 'API error';
                $this->error('  API error: ' . $msg);
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
            $reverseStatus = (string)($o['reverse_status'] ?? $o['reverseStatus'] ?? $o['status'] ?? '');
            $reverseType = (string)($o['reverse_type'] ?? $o['reverseType'] ?? $o['type'] ?? '');
            $reason = (string)($o['reason'] ?? $o['reverse_reason'] ?? '');
            $refundAmount = $o['refund_amount'] ?? $o['refundAmount'] ?? $o['actual_refund_amount'] ?? null;
            $currency = (string)($o['currency'] ?? '');
            $items = $o['items'] ?? $o['reverse_order_items'] ?? $o['reverseOrderItems'] ?? null;

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

    /**
     * Fetch order items from Lazada API and store as LazadaOrderProduct rows.
     */
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

            LazadaOrderProduct::query()->updateOrCreate(
                [
                    'lazada_order_id' => $order->id,
                    'order_item_id'   => $orderItemId,
                ],
                [
                    'sku'       => $sellerSku !== '' ? $sellerSku : null,
                    'name'      => $name !== '' ? $name : null,
                    'variation' => $variation,
                    'quantity'  => $qty,
                    'image'     => $image !== '' ? $image : null,
                    'status'    => isset($it['status']) && is_string($it['status']) ? $it['status'] : null,
                    'raw'       => $it,
                ]
            );
        }
    }

    /**
     * Signed API call with rate limiting and retry (mirrors LazadaOrderController logic).
     */
    private function runSignedApiCall(LazadaClient $client, object $setting, string $method, string $apiPath, array $customParams, ?string $pack = null): array
    {
        $apiPath = trim($apiPath);
        if (!str_starts_with($apiPath, '/')) $apiPath = '/' . $apiPath;

        $timestamp = (string) round(microtime(true) * 1000);
        $params = [
            'app_key'     => (string) $setting->app_key,
            'sign_method' => 'sha256',
            'timestamp'   => $timestamp,
            'access_token' => (string) $setting->access_token,
        ];

        foreach ($customParams as $k => $v) {
            if (!is_string($k) || $k === '' || in_array($k, ['sign', 'app_key', 'sign_method', 'timestamp'], true)) continue;
            $params[$k] = is_scalar($v) || $v === null ? $v : json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $method = strtoupper($method);
        $rateKey = 'lazada_api_last_call:' . $setting->region . ':' . $setting->app_key;
        $lockKey = 'lazada_api_lock:' . $setting->region . ':' . $setting->app_key;
        $minIntervalMs = 1200;

        $callOnce = function () use ($client, $setting, $apiPath, &$params, $method) {
            $params['sign'] = $client->sign($apiPath, $params, (string) $setting->app_secret);
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
}
