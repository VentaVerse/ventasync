<?php

namespace App\Console\Commands;

use Extensions\tiktok\Models\TikTokApiLog;
use Extensions\tiktok\Models\TikTokOrder;
use Extensions\tiktok\Models\TikTokOrderProduct;
use Extensions\tiktok\Models\TikTokSetting;
use Extensions\tiktok\Services\TikTok\TikTokClient;
use Extensions\tiktok\Services\TikTokCatalogOrderSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TikTokSyncOrders extends Command
{
    protected $signature = 'tiktok:sync-orders';

    protected $description = 'Sync orders from TikTok Shop (create new + update existing + catalog sync)';

    public function handle(): int
    {
        $raw = TikTokSetting::query()->first();
        if (!$raw) {
            $this->error('TikTok settings not configured.');
            return 1;
        }

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

        $syncDays = max(1, (int) ($raw->sync_last_days ?? 15));
        $tz = new \DateTimeZone('Asia/Manila');
        $from = (new \DateTime('now', $tz))->modify("-{$syncDays} days")->setTime(0, 0, 0)->getTimestamp();
        $to = (new \DateTime('now', $tz))->setTime(23, 59, 59)->getTimestamp();

        $this->info("Syncing TikTok orders from last {$syncDays} days...");

        $client = new TikTokClient();
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
            $raw->update(['last_order_sync_at' => now()]);
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

            // Sync line items
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

            // Sync to core ERP catalog order
            try {
                $dbOrder->refresh();
                $catalogSync->sync($dbOrder);
            } catch (\Throwable $e) {
                Log::warning('TikTok catalog sync failed for order ' . $orderId . ': ' . $e->getMessage());
            }
        }

        $raw->update(['last_order_sync_at' => now()]);

        $this->info("Synced: {$saved} new, {$updated} updated (" . count($allOrders) . " total).");
        return 0;
    }
}
