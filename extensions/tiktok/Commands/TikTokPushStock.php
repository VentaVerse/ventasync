<?php

namespace Extensions\tiktok\Commands;

use Extensions\tiktok\Models\TikTokApiLog;
use Extensions\tiktok\Models\TikTokProductGroupProduct;
use Extensions\tiktok\Models\TikTokSetting;
use Extensions\tiktok\Services\TikTok\TikTokClient;
use Extensions\tiktok\Services\TikTok\TikTokStockPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TikTokPushStock extends Command
{
    protected $signature = 'tiktok:push-stock';

    protected $description = 'Push ERP product quantities to TikTok Shop for all linked products';

    public function handle(): int
    {
        $raw = TikTokSetting::query()->first();
        if (!$raw) {
            $this->error('TikTok settings not configured.');
            return 1;
        }

        $s = $raw->decrypted();
        $sandbox = $raw->mode === 'sandbox';

        $appKey     = $sandbox ? ($s->sandbox_app_key ?? '') : ($s->app_key ?? '');
        $appSecret  = $sandbox ? ($s->sandbox_app_secret ?? '') : ($s->app_secret ?? '');
        $token      = $sandbox ? ($s->sandbox_access_token ?? '') : ($s->access_token ?? '');
        $shopCipher = $sandbox ? ($raw->sandbox_shop_cipher ?? '') : ($raw->shop_cipher ?? '');
        $warehouseId = $raw->warehouse_id ?: null;

        if (!$appKey || !$appSecret || !$token) {
            $this->error('Missing TikTok credentials/token.');
            return 1;
        }

        $expiresAt = $sandbox ? $raw->sandbox_expires_at : $raw->expires_at;
        if ($expiresAt && $expiresAt->isPast()) {
            $this->warn('TikTok access token expired. Refresh it first.');
            return 1;
        }

        $pfx = (string) config('catalog.prefix');
        $client = app(TikTokClient::class);

        // Get all pivot rows that have been pushed to TikTok
        $pivotRows = TikTokProductGroupProduct::query()
            ->whereNotNull('tiktok_product_id')
            ->where('tiktok_product_id', '!=', '')
            ->get();

        if ($pivotRows->isEmpty()) {
            $this->info('No TikTok products linked.');
            $raw->update(['last_stock_push_at' => now()]);
            return 0;
        }

        // A bare product pushed before ids were stored: ask TikTok once.
        foreach ($pivotRows as $pivot) {
            if (!$pivot->tiktok_sku_id) {
                $detail = $client->getProduct($appKey, $appSecret, $token, $pivot->tiktok_product_id, $shopCipher);
                $found = $detail['body']['data']['skus'][0]['id'] ?? null;
                if ($found) {
                    $pivot->update(['tiktok_sku_id' => (string) $found]);
                    $pivot->tiktok_sku_id = (string) $found;
                }
            }
        }

        // The ONE resolver, shared with the group page: combinations first,
        // then option values, then the bare product. The old loop read
        // product_option_value alone and pushed the wrong figure for every
        // multi-axis product on every tick.
        $resolved = app(TikTokStockPushService::class)->resolve($pivotRows, $pfx);

        $this->info("Pushing stock for {$pivotRows->count()} product(s)...");

        $totalOk = 0;
        $totalErr = 0;
        $skipped = 0;

        foreach ($resolved as $row) {
            $pivot = $row['pivot'];
            if ($row['product_status'] === 0) {
                $skipped++;
                continue;
            }

            $skuPayload = [];
            foreach ($row['skus'] as $sku) {
                $inv = ['quantity' => $sku['quantity']];
                if ($warehouseId) $inv['warehouse_id'] = $warehouseId;
                $skuPayload[] = ['id' => $sku['id'], 'inventory' => [$inv]];
            }
            if (empty($skuPayload)) {
                $totalErr++;
                continue;
            }

            $result = $client->updateInventory($appKey, $appSecret, $token, $pivot->tiktok_product_id, $skuPayload, $shopCipher);

            TikTokApiLog::safeCreate([
                'pack'            => 'push-stock-cron',
                'method'          => 'POST',
                'api_path'        => '/product/202309/products/' . $pivot->tiktok_product_id . '/inventory/update',
                'auth_required'   => true,
                'request_params'  => ['skus' => $skuPayload],
                'response_status' => $result['status'] ?? 0,
                'ok'              => $result['ok'] ?? false,
                'response_body'   => $result['body'] ?? [],
                'user_id'         => null,
            ]);

            $apiCode = (int) ($result['body']['code'] ?? -1);
            if ($apiCode === 0) {
                $totalOk++;
            } else {
                $totalErr++;
                $msg = $result['body']['message'] ?? 'Unknown error';
                Log::warning("TikTok push-stock failed for product {$pivot->tiktok_product_id}: {$msg}");
            }

            usleep(300000); // 300ms rate limit
        }

        $raw->update(['last_stock_push_at' => now()]);

        $this->info("Done. Success: {$totalOk}, Failed: {$totalErr}, Skipped: {$skipped}");
        return $totalErr > 0 ? 1 : 0;
    }

}
