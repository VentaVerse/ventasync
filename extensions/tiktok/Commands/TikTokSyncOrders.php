<?php

namespace Extensions\tiktok\Commands;

use Extensions\tiktok\Models\TikTokApiLog;
use Extensions\tiktok\Models\TikTokOrder;
use Extensions\tiktok\Models\TikTokOrderProduct;
use Extensions\tiktok\Models\TikTokSetting;
use Extensions\tiktok\Services\TikTok\TikTokClient;
use Extensions\tiktok\Services\TikTok\TikTokReturnSync;
use Extensions\tiktok\Services\TikTokCatalogOrderSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TikTokSyncOrders extends Command implements \App\Integrations\Contracts\SteppableAutomation
{
    use \App\Console\Concerns\StepsOutsideArtisan;

    protected $signature = 'tiktok:sync-orders
        {--days= : Override sync_last_days for this run (passed by the universal scheduler)}
        {--both : Sync orders and returns (default behavior)}
        {--returns : Sync returns and refunds only}
        {--no-returns : Skip the returns sync}
        {--days-returns= : Override sync_last_days_returns for this run}';

    protected $description = 'Sync orders from TikTok Shop (create new + update existing + returns)';

    public function handle(): int
    {
        $stores = \Extensions\tiktok\Models\TikTokSetting::enabledStores();
        if ($stores->isEmpty()) { $this->error('No enabled TikTok store. Configure TikTok settings first.'); return 1; }
        $worst = 0;
        foreach ($stores as $store) {
            $label = $store->store_name ?: ('#' . $store->id);
            $this->info("=== TikTok store: {$label} ===");
            app()->instance('tiktok.route-store', $store);
            try {
                $worst = max($worst, $this->handleStore($store));
            } catch (\Throwable $e) {
                $this->error("Store {$label} failed: " . $e->getMessage());
                \Illuminate\Support\Facades\Log::error('TikTok '.class_basename($this).': store failed', ['store' => $store->id, 'error' => $e->getMessage()]);
                $worst = 1;
            }
        }
        app()->forgetInstance('tiktok.route-store');
        return $worst;
    }

    private function handleStore(\Extensions\tiktok\Models\TikTokSetting $store): int
    {
        $raw = $store;

        $returnsOnly = (bool) $this->option('returns');
        $skipReturns = (bool) $this->option('no-returns');

        if (!$returnsOnly) {
            $ordersExit = $this->syncOrders($raw);
            if ($ordersExit !== 0) {
                return $ordersExit;
            }

        }

        if (!$skipReturns) {
            $this->info('--- Syncing TikTok returns ---');
            $this->syncReturns($raw);
        }

        return 0;
    }

    private function syncReturns(TikTokSetting $raw): void
    {
        $creds = TikTokReturnSync::credsFrom($raw);
        if (!$creds) {
            $this->error('Missing TikTok credentials. Configure TikTok settings first.');
            return;
        }

        $cliDays = $this->option('days-returns');
        $syncDays = $cliDays !== null && $cliDays !== ''
            ? max(1, (int) $cliDays)
            : (((int) ($raw->sync_last_days_returns ?? 0)) > 0 ? (int) $raw->sync_last_days_returns : 14);
        $tz = new \DateTimeZone('Asia/Manila');
        $from = (new \DateTime('now', $tz))->modify("-{$syncDays} days")->setTime(0, 0, 0)->getTimestamp();
        $this->info("  Returns window: last {$syncDays} days");

        $result = app(TikTokReturnSync::class)->sync($creds, $from, null, null);
        if ($result['ok']) {
            $this->info('  ' . $result['message']);
        } else {
            $this->error('  ' . $result['message']);
        }
    }

    private function syncOrders(TikTokSetting $raw): int
    {

        $s = $raw->decrypted();
        $sandbox = $raw->mode === 'sandbox';

        $appKey    = $sandbox ? ($s->sandbox_app_key ?? '') : ($s->app_key ?? '');
        $appSecret = $sandbox ? ($s->sandbox_app_secret ?? '') : ($s->app_secret ?? '');
        $token     = $sandbox ? ($s->sandbox_access_token ?? '') : ($s->access_token ?? '');
        $shopCipher = $sandbox ? ($raw->sandbox_shop_cipher ?? '') : ($raw->shop_cipher ?? '');

        if (!$appKey || !$appSecret || !$token) {
            $this->error('Missing TikTok credentials. Configure TikTok settings first.');
            return 1;
        }

        $expiresAt = $sandbox ? $raw->sandbox_expires_at : $raw->expires_at;
        if ($expiresAt && $expiresAt->isPast()) {
            $this->warn('TikTok access token expired (' . $expiresAt . '). Refresh it first.');
            return 1;
        }

        $cliDays = $this->option('days');
        $syncDays = $cliDays !== null && $cliDays !== ''
            ? max(1, (int) $cliDays)
            : max(1, (int) ($raw->sync_last_days ?? 15));
        $tz = new \DateTimeZone('Asia/Manila');
        $from = (new \DateTime('now', $tz))->modify("-{$syncDays} days")->setTime(0, 0, 0)->getTimestamp();
        $to = (new \DateTime('now', $tz))->setTime(23, 59, 59)->getTimestamp();

        $this->info("Syncing TikTok orders from last {$syncDays} days...");

        $client = app(TikTokClient::class);
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

            $result = $client->searchOrders($appKey, $appSecret, $token, $pageSize, $body, $shopCipher);

            TikTokApiLog::safeCreate([
                'pack'            => 'order-sync-cron',
                'method'          => 'POST',
                'api_path'        => '/order/202309/orders/search',
                'auth_required'   => true,
                'request_params'  => $body,
                'response_status' => $result['status'] ?? 0,
                'ok'              => $result['ok'] ?? false,
                'response_body'   => $result['body'] ?? [],
                'user_id'         => null,
            ]);

            $apiCode = (int) ($result['body']['code'] ?? -1);
            if ($apiCode !== 0) {
                $msg = $result['body']['message'] ?? 'Unknown error';
                $this->error('API error: ' . $msg);
                return 1;
            }

            $orderList = $result['body']['data']['orders'] ?? [];
            $allOrders = array_merge($allOrders, $orderList);

            $nextToken = $result['body']['data']['next_page_token'] ?? '';
            if ($nextToken === '' || count($orderList) < $pageSize) break;
            $pages++;
        }

        if (empty($allOrders)) {
            $this->info('No orders found.');
            $this->info('--- Backfilling missing TikTok fees ---');
            $this->backfillMissingFees($client, [
                'app_key'     => $appKey,
                'app_secret'  => $appSecret,
                'token'       => $token,
                'shop_cipher' => $shopCipher,
            ]);
            $raw->forceFill(['last_order_sync_at' => now()])->save();
            return 0;
        }

        $saved = 0;
        $updated = 0;
        $catalogSync = new TikTokCatalogOrderSync();
        $region = $raw->region ?? 'PH';

        foreach ($allOrders as $o) {
            $orderId = (string) ($o['id'] ?? '');
            if ($orderId === '') continue;

            $status = $o['status'] ?? null;
            $createdAt = isset($o['create_time']) ? \Carbon\Carbon::createFromTimestamp((int) $o['create_time']) : null;
            $updatedAt = isset($o['update_time']) ? \Carbon\Carbon::createFromTimestamp((int) $o['update_time']) : null;
            $buyer = $o['recipient_address']['name'] ?? '';

            $existing = TikTokOrder::where('order_id', $orderId)->first();

            $orderData = [
                'region'           => $region,
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

            $lineItems = $o['line_items'] ?? [];
            $existingItemIds = $dbOrder->products()->pluck('order_line_item_id')->toArray();

            foreach ($lineItems as $li) {
                $lineItemId = (string) ($li['id'] ?? '');
                $itemData = [
                    'tiktok_order_id'    => $dbOrder->id,
                    'order_line_item_id' => $lineItemId,
                    'sku'                => $li['seller_sku'] ?? $li['sku_id'] ?? '',
                    'name'               => $li['product_name'] ?? '',
                    'variation'          => $li['sku_name'] ?? '',
                    'quantity'           => (int) ($li['quantity'] ?? 1),
                    'item_price'         => (float) ($li['original_price'] ?? 0),
                    'sale_price'         => (float) ($li['sale_price'] ?? 0),
                    'status'             => $li['display_status'] ?? $status,
                    'image'              => $li['sku_image'] ?? $li['product_image'] ?? '',
                    'raw'                => $li,
                ];

                if (in_array($lineItemId, $existingItemIds)) {
                    TikTokOrderProduct::where('tiktok_order_id', $dbOrder->id)
                        ->where('order_line_item_id', $lineItemId)
                        ->update($itemData);
                } else {
                    TikTokOrderProduct::create($itemData);
                }
            }

            try {
                $dbOrder->refresh();
                $catalogSync->sync($dbOrder);
            } catch (\Throwable $e) {
                Log::warning('TikTok catalog sync failed for order ' . $orderId . ': ' . $e->getMessage());
            }

            $this->fetchFeesIfReady($client, [
                'app_key'     => $appKey,
                'app_secret'  => $appSecret,
                'token'       => $token,
                'shop_cipher' => $shopCipher,
            ], $dbOrder);
        }

        $this->info('--- Backfilling missing TikTok fees ---');
        $this->backfillMissingFees($client, [
            'app_key'     => $appKey,
            'app_secret'  => $appSecret,
            'token'       => $token,
            'shop_cipher' => $shopCipher,
        ]);

        $raw->forceFill(['last_order_sync_at' => now()])->save();

        $this->info("Synced: {$saved} new, {$updated} updated (" . count($allOrders) . " total).");
        return 0;
    }

    private function fetchFeesIfReady(TikTokClient $client, array $creds, TikTokOrder $order): void
    {
        try {
            $status = strtoupper(trim((string) $order->status));

            if (in_array($status, ['CANCELLED', 'UNPAID'])) {
                return;
            }

            $existingFees = $order->fees;
            if (is_array($existingFees) && isset($existingFees['commission'])) {
                return;
            }

            $result = $client->getOrderStatementTransactions(
                $creds['app_key'],
                $creds['app_secret'],
                $creds['token'],
                (string) $order->order_id,
                $creds['shop_cipher']
            );

            $apiCode = (int) ($result['body']['code'] ?? -1);

            TikTokApiLog::safeCreate([
                'pack'            => 'fee-sync',
                'method'          => 'GET',
                'api_path'        => '/finance/202501/orders/' . $order->order_id . '/statement_transactions',
                'auth_required'   => true,
                'request_params'  => ['order_id' => $order->order_id],
                'response_status' => $result['status'] ?? 0,
                'ok'              => ($result['ok'] ?? false) && $apiCode === 0,
                'response_body'   => $result['body'] ?? [],
                'user_id'         => null,
            ]);

            if ($apiCode !== 0) {
                return;
            }

            $data = $result['body']['data'] ?? [];
            $lines = is_array($data) ? ($data['sku_transactions'] ?? []) : [];
            if (!is_array($lines) || empty($lines)) {
                return;
            }

            $commissionTotal     = 0.0;
            $transactionFeeTotal = 0.0;
            $shippingFeeTotal    = 0.0;

            foreach ($lines as $line) {
                if (!is_array($line)) continue;

                $fee = $line['fee_tax_breakdown']['fee'] ?? [];
                $shipping = $line['shipping_cost_breakdown'] ?? [];
                $commissionTotal     += abs((float) ($fee['platform_commission_amount'] ?? 0));
                $transactionFeeTotal += abs((float) ($fee['transaction_fee_amount'] ?? 0));
                $shippingFeeTotal    += abs((float) ($shipping['actual_shipping_fee_amount'] ?? 0));
            }

            $fees = is_array($existingFees) ? $existingFees : [];
            $fees['commission']        = round($commissionTotal, 2);
            $fees['transaction_fee']   = round($transactionFeeTotal, 2);
            $fees['shipping_fee']      = round($shippingFeeTotal, 2);
            $fees['settlement_amount'] = round((float) ($data['settlement_amount'] ?? 0), 2);
            $fees['revenue']           = round((float) ($data['revenue_amount'] ?? 0), 2);
            $fees['statement'] = $data;

            $order->fees = $fees;
            $order->save();
        } catch (\Throwable $e) {
            Log::warning('TikTok fee fetch failed for order ' . $order->order_id . ': ' . $e->getMessage());
        }
    }

    private function backfillMissingFees(TikTokClient $client, array $creds): void
    {
        $orders = TikTokOrder::query()
            ->whereNull('fees')
            ->whereNotIn('status', ['CANCELLED'])
            ->get();

        if ($orders->isEmpty()) {
            $this->info('  No orders need fee backfill.');
            return;
        }

        $this->info("  Found {$orders->count()} orders to backfill fees...");

        $filled = 0;
        foreach ($orders as $order) {
            $before = $order->fees;
            $this->fetchFeesIfReady($client, $creds, $order);
            $after = $order->fresh()->fees;

            if (!empty($after) && $after !== $before) {
                $filled++;
            }

            usleep(300000);
        }

        $this->info("  Backfilled fees for {$filled}/{$orders->count()} orders.");
    }

    public function automationUnits(\App\Models\ScheduledJob $job): array
    {
        $args = $job->commandArguments();
        $returnsOnly = ! empty($args['--returns']);
        $skipReturns = ! empty($args['--no-returns']);
        $units = [];
        foreach (TikTokSetting::enabledStores() as $store) {
            foreach (array_values(array_filter(['orders', 'returns'], fn ($p) => $returnsOnly ? $p === 'returns' : ($p !== 'returns' || ! $skipReturns))) as $phase) {
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
        $ok = 0;
        $failed = 0;
        $notes = [];
        foreach ($units as $unit) {
            [$sid, $phase] = array_pad(explode(':', (string) $unit, 2), 2, '');
            $store = TikTokSetting::query()->find((int) $sid);
            if (! $store) {
                $failed++;
                continue;
            }
            app()->instance('tiktok.route-store', $store);
            try {
                if ($phase === 'orders') {
                    $exit = $this->syncOrders($store);
                    $exit === 0 ? $ok++ : $failed++;
                    if ($exit !== 0) {
                        $notes[] = 'orders: TikTok Shop refused the sync';
                    }
                } elseif ($phase === 'returns') {
                    $this->syncReturns($store);
                    $ok++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $notes[] = $phase . ': ' . \App\Support\TransportError::plain($e, 'TikTok Shop');
            } finally {
                app()->forgetInstance('tiktok.route-store');
            }
        }

        return ['ok' => $ok, 'failed' => $failed, 'note' => implode('; ', array_slice($notes, 0, 2))];
    }
}
