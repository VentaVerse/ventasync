<?php

namespace App\Console\Commands;

use Extensions\tiktok\Models\TikTokApiLog;
use Extensions\tiktok\Models\TikTokSetting;
use Extensions\tiktok\Models\TikTokProductGroup;
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

        // Get all product groups with TikTok product IDs
        $groups = TikTokProductGroup::query()
            ->whereNotNull('tiktok_product_id')
            ->where('tiktok_product_id', '!=', '')
            ->with('items')
            ->get();

        if ($groups->isEmpty()) {
            $this->info('No TikTok product groups with linked products.');
            $raw->update(['last_stock_push_at' => now()]);
            return 0;
        }

        $this->info("Pushing stock for {$groups->count()} product group(s)...");

        $totalOk = 0;
        $totalErr = 0;
        $skipped = 0;

        foreach ($groups as $group) {
            $tiktokProductId = (string) $group->tiktok_product_id;

            // Build SKU inventory from the group's ERP product
            $skus = $this->buildSkuInventory($pfx, $group);

            if (empty($skus)) {
                $skipped++;
                continue;
            }

            $result = $client->updateInventory($appKey, $appSecret, $token, $tiktokProductId, $skus, $shopCipher);

            TikTokApiLog::safeCreate([
                'pack'            => 'push-stock-cron',
                'method'          => 'POST',
                'api_path'        => '/product/202309/products/' . $tiktokProductId . '/inventory/update',
                'auth_required'   => true,
                'request_params'  => ['skus' => $skus],
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
                Log::warning("TikTok push-stock failed for product {$tiktokProductId}: {$msg}");
            }
        }

        $raw->update(['last_stock_push_at' => now()]);

        $this->info("Done. Success: {$totalOk}, Failed: {$totalErr}, Skipped: {$skipped}");
        return $totalErr > 0 ? 1 : 0;
    }

    private function buildSkuInventory(string $pfx, TikTokProductGroup $group): array
    {
        $skus = [];

        // Get the first linked ERP product (product groups typically link to one product)
        $firstItem = $group->items->first();
        if (!$firstItem || !$firstItem->product_id) {
            return $skus;
        }

        $productId = (int) $firstItem->product_id;

        $product = DB::table($pfx . 'product')
            ->where('product_id', $productId)
            ->first(['product_id', 'quantity', 'status']);

        if (!$product || (int) $product->status === 0) {
            return $skus;
        }

        // Check for option values (variants)
        $options = DB::table($pfx . 'product_option_value')
            ->where('product_id', $productId)
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->get(['product_option_value_id', 'sku', 'quantity']);

        // Get stored SKU mapping from the group's raw data
        $groupRaw = is_array($group->raw) ? $group->raw : [];
        $tiktokSkus = $groupRaw['skus'] ?? [];

        if ($options->isNotEmpty()) {
            // Product has variants — match by seller_sku
            $erpSkuMap = [];
            foreach ($options as $opt) {
                $erpSkuMap[trim((string) $opt->sku)] = max(0, (int) $opt->quantity);
            }

            foreach ($tiktokSkus as $ttSku) {
                $ttSkuId = $ttSku['id'] ?? null;
                $sellerSku = '';
                foreach ($ttSku['sales_attributes'] ?? [] as $attr) {
                    if (!empty($attr['sku_img'])) continue;
                    $sellerSku = $attr['custom_value'] ?? $attr['value_name'] ?? '';
                }
                // Try matching by seller_sku in the SKU identifiers
                foreach ($ttSku['seller_sku'] ?? [] as $ss) {
                    $sellerSku = $ss;
                }
                // Fallback: direct seller_sku field
                if (isset($ttSku['seller_sku']) && is_string($ttSku['seller_sku'])) {
                    $sellerSku = $ttSku['seller_sku'];
                }

                if ($ttSkuId && isset($erpSkuMap[$sellerSku])) {
                    $skus[] = [
                        'id' => (string) $ttSkuId,
                        'inventory' => [
                            ['quantity' => $erpSkuMap[$sellerSku]],
                        ],
                    ];
                }
            }
        } else {
            // Simple product — push base quantity to all TikTok SKUs
            $qty = max(0, (int) $product->quantity);
            foreach ($tiktokSkus as $ttSku) {
                $ttSkuId = $ttSku['id'] ?? null;
                if ($ttSkuId) {
                    $skus[] = [
                        'id' => (string) $ttSkuId,
                        'inventory' => [
                            ['quantity' => $qty],
                        ],
                    ];
                }
            }
        }

        return $skus;
    }
}
