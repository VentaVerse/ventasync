<?php

namespace App\Console\Commands;

use App\Models\LazadaApiLog;
use App\Models\LazadaProduct;
use App\Models\LazadaProductVariant;
use App\Models\LazadaSetting;
use App\Services\Lazada\LazadaClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LazadaPushStock extends Command
{
    protected $signature = 'lazada:push-stock
        {--product-ids= : Comma-separated Lazada listing IDs to push (lazada_products.id)}';

    protected $description = 'Push ERP product quantities to Lazada for all linked products';

    public function handle(LazadaClient $client): int
    {
        $setting = $this->decryptSetting(LazadaSetting::query()->first());
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret || !$setting->access_token) {
            $this->error('Missing Lazada credentials/token. Configure Lazada settings first.');
            return 1;
        }

        $query = LazadaProduct::query()->where('product_id', '>', 0);

        if ($this->option('product-ids')) {
            $ids = array_map('intval', explode(',', $this->option('product-ids')));
            $query->whereIn('id', $ids);
        }

        $listings = $query->get();

        if ($listings->isEmpty()) {
            $this->warn('No Lazada listings with linked ERP products found.');
            return 0;
        }

        $this->info("Pushing stock for {$listings->count()} listing(s)...");

        $pfx = (string) config('catalog.prefix');
        $totalOk = 0;
        $totalErr = 0;
        $totalSkipped = 0;

        foreach ($listings as $listing) {
            $productId = (int) $listing->product_id;

            $product = DB::table($pfx . 'product')
                ->where('product_id', $productId)
                ->first(['product_id', 'sku', 'quantity']);

            if (!$product) {
                $totalSkipped++;
                continue;
            }

            // Build list of {seller_sku, quantity} to push.
            // Primary source: lazada_product_variants (holds the actual Lazada SellerSku).
            // Fallback: ERP option value SKUs / main product SKU (legacy products without variant rows).
            $pushItems = $this->buildPushItems($pfx, $listing, $product);

            if (empty($pushItems)) {
                $totalSkipped++;
                continue;
            }

            foreach ($pushItems as $item) {
                $sellerSku = $item['seller_sku'];
                $qty = $item['quantity'];

                $payloadXml = $this->buildStockXml($sellerSku, $qty);

                $result = $this->pushToLazada($client, $setting, '/product/stock/sellable/update', $payloadXml);

                $body = $result['body'] ?? [];
                $code = is_array($body) ? ($body['code'] ?? null) : null;

                if (($result['ok'] ?? false) && ($code === '0' || $code === 0 || $code === null)) {
                    $totalOk++;
                } else {
                    $totalErr++;
                    $msg = is_array($body) ? ($body['message'] ?? json_encode($body)) : (string) $body;
                    Log::warning("Lazada push-stock failed for SKU {$sellerSku}", ['response' => $msg]);
                }
            }
        }

        $this->info("Done. Success: {$totalOk}, Failed: {$totalErr}, Skipped: {$totalSkipped}");

        return $totalErr > 0 ? 1 : 0;
    }

    /**
     * Build the list of {seller_sku, quantity} items to push for one listing.
     *
     * Strategy:
     * 1) If lazada_product_variants has rows → use those (they hold the real Lazada SellerSku).
     *    Quantity comes from ERP via product_option_value_id mapping, or main product qty.
     * 2) Fallback: use ERP option value SKUs directly (legacy products without variant rows).
     * 3) Last resort: use the main ERP product SKU + quantity.
     */
    private function buildPushItems(string $pfx, LazadaProduct $listing, object $product): array
    {
        $productId = (int) $listing->product_id;
        $items = [];

        // --- Strategy 1: lazada_product_variants ---
        $lazadaVariants = LazadaProductVariant::where('lazada_product_id', $listing->id)->get();

        if ($lazadaVariants->isNotEmpty()) {
            foreach ($lazadaVariants as $variant) {
                $sellerSku = trim((string) ($variant->seller_sku ?? ''));
                if ($sellerSku === '') continue;

                $povId = $variant->product_option_value_id;
                if ($povId) {
                    $erpQty = DB::table($pfx . 'product_option_value')
                        ->where('product_option_value_id', $povId)
                        ->value('quantity');
                    $qty = max(0, (int) ($erpQty ?? 0));
                } else {
                    $qty = max(0, (int) ($product->quantity ?? 0));
                }

                $items[] = ['seller_sku' => $sellerSku, 'quantity' => $qty];
            }

            if (!empty($items)) {
                return $items;
            }
        }

        // --- Strategy 2: ERP option value SKUs ---
        $erpVariants = DB::table($pfx . 'product_option_value')
            ->where('product_id', $productId)
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->orderBy('product_option_value_id')
            ->get(['sku', 'quantity']);

        foreach ($erpVariants as $r) {
            $sku = trim((string) ($r->sku ?? ''));
            if ($sku === '') continue;
            $items[] = ['seller_sku' => $sku, 'quantity' => max(0, (int) ($r->quantity ?? 0))];
        }

        if (!empty($items)) {
            return $items;
        }

        // --- Strategy 3: main product SKU ---
        $sku = trim((string) ($product->sku ?? ''));
        if ($sku !== '') {
            $items[] = ['seller_sku' => $sku, 'quantity' => max(0, (int) ($product->quantity ?? 0))];
        }

        return $items;
    }

    private function buildStockXml(string $sellerSku, int $qty): string
    {
        $sellerSku = htmlspecialchars($sellerSku, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $qty = max(0, $qty);

        return '<Request>'
            . '<Product><Skus><Sku>'
            . '<SellerSku>' . $sellerSku . '</SellerSku>'
            . '<SellableQuantity>' . $qty . '</SellableQuantity>'
            . '</Sku></Skus></Product>'
            . '</Request>';
    }

    private function pushToLazada(LazadaClient $client, object $setting, string $apiPath, string $payloadXml): array
    {
        $rateKey = 'lazada_api_last_call:' . $setting->region . ':' . $setting->app_key;
        $lockKey = 'lazada_api_lock:' . $setting->region . ':' . $setting->app_key;
        $minIntervalMs = 1200;

        $params = [
            'app_key'      => (string) $setting->app_key,
            'sign_method'  => 'sha256',
            'timestamp'    => (string) round(microtime(true) * 1000),
            'access_token' => (string) $setting->access_token,
            'payload'      => $payloadXml,
        ];

        $callOnce = function () use ($client, $setting, $apiPath, &$params) {
            $params['sign'] = $client->sign($apiPath, $params, (string) $setting->app_secret);
            return $client->post($setting->region, $apiPath, $params);
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
                $params['timestamp'] = (string) round(microtime(true) * 1000);
                continue;
            }

            break;
        }

        if ($result === null) {
            $result = ['status' => 0, 'ok' => false, 'body' => ['message' => 'API call failed']];
        }

        LazadaApiLog::safeCreate([
            'pack'            => 'lazada.product.stock.sellable.update.cron',
            'method'          => 'POST',
            'api_path'        => $apiPath,
            'auth_required'   => true,
            'request_params'  => $params,
            'response_status' => (int) ($result['status'] ?? 0),
            'ok'              => (bool) ($result['ok'] ?? false),
            'response_body'   => $result['body'] ?? null,
            'user_id'         => null,
        ], force: true);

        return $result;
    }

    private function decryptSetting(?LazadaSetting $setting): ?object
    {
        if (!$setting) return null;

        $s = (object) $setting->toArray();

        foreach (['app_secret', 'auth_code', 'access_token', 'refresh_token'] as $k) {
            if (!empty($s->$k)) {
                try {
                    $s->$k = decrypt($s->$k);
                } catch (\Throwable $e) {
                    $s->$k = $s->$k;
                }
            }
        }

        return $s;
    }
}
