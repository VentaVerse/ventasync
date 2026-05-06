<?php

namespace Extensions\tiktok\Commands;

use Extensions\tiktok\Models\TikTokApiLog;
use Extensions\tiktok\Models\TikTokProductGroupProduct;
use Extensions\tiktok\Models\TikTokSetting;
use Extensions\tiktok\Services\TikTok\TikTokClient;
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
        $client = new TikTokClient();

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

        // Preload ERP product data
        $productIds = $pivotRows->pluck('product_id')->unique()->values()->all();
        $erpProducts = DB::table($pfx . 'product')
            ->whereIn('product_id', $productIds)
            ->get(['product_id', 'quantity', 'status'])
            ->keyBy('product_id');

        // Preload option values for all products
        $erpOptionsByProduct = DB::table($pfx . 'product_option_value')
            ->whereIn('product_id', $productIds)
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->get(['product_id', 'product_option_value_id', 'option_value_id', 'sku', 'quantity'])
            ->groupBy('product_id');

        $this->info("Pushing stock for {$pivotRows->count()} product(s)...");

        $totalOk = 0;
        $totalErr = 0;
        $skipped = 0;

        foreach ($pivotRows as $pivot) {
            $product = $erpProducts->get((int) $pivot->product_id);
            if (!$product || (int) $product->status === 0) {
                $skipped++;
                continue;
            }

            $skuIdRaw = $pivot->tiktok_sku_id;
            $skuMap = $this->parseSkuIds($skuIdRaw);

            if ($skuMap === null) {
                // Single-variant product
                if (!$skuIdRaw) {
                    // Try fetching SKU ID from TikTok API
                    $detail = $client->getProduct($appKey, $appSecret, $token, $pivot->tiktok_product_id, $shopCipher);
                    $skuIdRaw = $detail['body']['data']['skus'][0]['id'] ?? null;
                    if ($skuIdRaw) {
                        $pivot->update(['tiktok_sku_id' => $skuIdRaw]);
                    }
                }
                if (!$skuIdRaw) {
                    $totalErr++;
                    continue;
                }

                $qty = max(0, (int) ($product->quantity ?? 0));
                $inv = ['quantity' => $qty];
                if ($warehouseId) $inv['warehouse_id'] = $warehouseId;
                $skuPayload = [['id' => $skuIdRaw, 'inventory' => [$inv]]];
            } else {
                // Multi-variant — build inventory for each option value
                $optionValues = $erpOptionsByProduct->get((int) $pivot->product_id, collect())->keyBy('option_value_id');
                $skuPayload = [];
                foreach ($skuMap as $ovId => $ttSkuId) {
                    if (!$ttSkuId) continue;
                    $ov = $optionValues->get($ovId);
                    $qty = $ov ? max(0, (int) $ov->quantity) : 0;
                    $inv = ['quantity' => $qty];
                    if ($warehouseId) $inv['warehouse_id'] = $warehouseId;
                    $skuPayload[] = ['id' => $ttSkuId, 'inventory' => [$inv]];
                }
                if (empty($skuPayload)) {
                    $totalErr++;
                    continue;
                }
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

    /**
     * Parse tiktok_sku_id value. Returns assoc array (option_value_id => sku_id)
     * for multi-variant products, or null for single-variant.
     */
    private function parseSkuIds(?string $skuIdRaw): ?array
    {
        if (!$skuIdRaw || $skuIdRaw[0] !== '{') {
            return null;
        }
        $decoded = json_decode($skuIdRaw, true);
        return is_array($decoded) ? $decoded : null;
    }
}
