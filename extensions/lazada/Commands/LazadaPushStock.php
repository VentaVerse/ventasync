<?php

namespace Extensions\lazada\Commands;

use Extensions\lazada\Models\LazadaApiLog;
use Extensions\lazada\Models\LazadaProduct;
use Extensions\lazada\Models\LazadaProductVariant;
use Extensions\lazada\Models\LazadaSetting;
use Extensions\lazada\Services\Lazada\LazadaClient;
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
        $setting = LazadaSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret || !$setting->access_token) {
            $this->error('Missing Lazada credentials/token. Configure Lazada settings first.');
            return 1;
        }

        $pfx = (string) config('catalog.prefix');

        // ── Phase 1: Resolve missing variants (capped per run) ──────────
        $this->resolveUnknownVariants($setting, $client, $pfx);

        // ── Phase 2: Push stock in batches ──────────────────────────────
        // Only load listings that actually have cached variant sku_ids — skip the rest at DB level
        $query = LazadaProduct::query()
            ->where('product_id', '>', 0)
            ->whereNotNull('lazada_item_id')
            ->where('lazada_item_id', '!=', '')
            ->whereHas('variants', fn ($q) => $q->whereNotNull('sku_id'));

        if ($this->option('product-ids')) {
            $ids = array_map('intval', explode(',', $this->option('product-ids')));
            $query->whereIn('id', $ids);
        }

        // Eager-load variants to avoid N+1 queries
        $listings = $query->with(['variants' => fn ($q) => $q->whereNotNull('sku_id')])->get();

        if ($listings->isEmpty()) {
            $this->warn('No Lazada listings with resolved variants found.');
            return 0;
        }

        $this->info("Pushing stock for {$listings->count()} listing(s)...");

        // Collect all push items across all listings first
        $allPushItems = [];
        $skipped = 0;

        foreach ($listings as $listing) {
            $product = DB::table($pfx . 'product')
                ->where('product_id', (int) $listing->product_id)
                ->first(['product_id', 'sku', 'quantity', 'status']);

            if (!$product) {
                $skipped++;
                continue;
            }

            if ((int) $product->status === 0) {
                $skipped++;
                continue;
            }

            $items = $this->buildPushItems($pfx, $listing, $product);
            if (empty($items)) {
                $skipped++;
                continue;
            }

            foreach ($items as $item) {
                $allPushItems[] = $item;
            }
        }

        if (empty($allPushItems)) {
            $this->info("No SKUs to push (skipped: {$skipped}).");
            LazadaSetting::query()->update(['last_stock_push_at' => now()]);
            return 0;
        }

        // Batch SKUs into groups of 20 per API call
        $batchSize = 20;
        $batches = array_chunk($allPushItems, $batchSize);
        $totalOk = 0;
        $totalErr = 0;

        $this->info("Pushing " . count($allPushItems) . " SKU(s) in " . count($batches) . " batch(es)...");

        foreach ($batches as $batch) {
            $payloadXml = $this->buildBatchStockXml($batch);
            $labels = array_map(fn ($i) => $i['seller_sku'] ?: "SkuId:{$i['sku_id']}", $batch);

            $result = $this->pushToLazada($client, $setting, '/product/stock/sellable/update', $payloadXml);

            $body = $result['body'] ?? [];
            $code = is_array($body) ? ($body['code'] ?? null) : null;

            if (($result['ok'] ?? false) && ($code === '0' || $code === 0 || $code === null)) {
                $totalOk += count($batch);
            } else {
                $totalErr += count($batch);
                $msg = is_array($body) ? ($body['message'] ?? json_encode($body)) : (string) $body;
                Log::warning('Lazada push-stock batch failed', [
                    'skus' => $labels,
                    'response' => $msg,
                ]);

                // Invalidate stale sku_ids so they get re-fetched on the next run
                $details = is_array($body) ? ($body['detail'] ?? []) : [];
                foreach ($details as $d) {
                    $errCode = $d['code'] ?? '';
                    $staleSkuId = $d['sku_id'] ?? null;
                    if ($errCode === 'E0207' && $staleSkuId) {
                        LazadaProductVariant::where('sku_id', (int) $staleSkuId)->update(['sku_id' => null]);
                        $this->warn("Invalidated stale sku_id {$staleSkuId} (SKU not exist on Lazada)");
                    }
                }
            }
        }

        $this->info("Done. Success: {$totalOk}, Failed: {$totalErr}, Skipped: {$skipped}");

        LazadaSetting::query()->update(['last_stock_push_at' => now()]);

        return $totalErr > 0 ? 1 : 0;
    }

    /**
     * Phase 1: For listings that have a lazada_item_id but no cached variants,
     * OR whose cached variants don't cover all ERP option SKUs (stale cache),
     * fetch variant details from the Lazada API and cache them.
     * Capped at 20 API calls per run to keep execution time bounded.
     */
    private function resolveUnknownVariants(object $setting, LazadaClient $client, string $pfx): void
    {
        $maxFetches = 20;
        $fetched = 0;

        // Part A: Listings with NO cached variants at all
        $noVariants = LazadaProduct::query()
            ->where('product_id', '>', 0)
            ->whereNotNull('lazada_item_id')
            ->where('lazada_item_id', '!=', '')
            ->whereDoesntHave('variants', fn ($q) => $q->whereNotNull('sku_id'))
            ->limit($maxFetches)
            ->get();

        if ($noVariants->isNotEmpty()) {
            $this->info("Resolving variants for {$noVariants->count()} listing(s) with no cached variants...");
            foreach ($noVariants as $listing) {
                $this->fetchAndCacheLazadaVariants($listing, $setting, $client, $pfx);
                $fetched++;
            }
        }

        // Part B: Listings with cached variants that don't cover all ERP option SKUs (stale cache)
        $remaining = $maxFetches - $fetched;
        if ($remaining <= 0) return;

        $staleListings = LazadaProduct::query()
            ->where('product_id', '>', 0)
            ->whereNotNull('lazada_item_id')
            ->where('lazada_item_id', '!=', '')
            ->whereHas('variants', fn ($q) => $q->whereNotNull('sku_id'))
            ->limit($remaining)
            ->get();

        $refreshed = 0;
        foreach ($staleListings as $listing) {
            $cachedSkus = LazadaProductVariant::where('lazada_product_id', $listing->id)
                ->whereNotNull('sku_id')
                ->pluck('seller_sku')
                ->map(fn ($s) => trim((string) $s))
                ->toArray();

            $erpSkus = DB::table($pfx . 'product_option_value')
                ->where('product_id', (int) $listing->product_id)
                ->whereNotNull('sku')
                ->where('sku', '!=', '')
                ->pluck('sku')
                ->map(fn ($s) => trim((string) $s))
                ->filter(fn ($s) => $s !== '')
                ->toArray();

            if (empty($erpSkus) || empty(array_diff($erpSkus, $cachedSkus))) {
                continue; // Cache is up-to-date
            }

            $this->fetchAndCacheLazadaVariants($listing, $setting, $client, $pfx);
            $refreshed++;
            if ($refreshed >= $remaining) break;
        }

        if ($refreshed > 0) {
            $this->info("Refreshed stale variant cache for {$refreshed} listing(s).");
        }
    }

    /**
     * Build the list of {sku_id, seller_sku, quantity} items for one listing.
     * Uses eager-loaded variants from the listing.
     */
    private function buildPushItems(string $pfx, LazadaProduct $listing, object $product): array
    {
        $items = [];
        $lazadaVariants = $listing->variants; // eager-loaded

        if ($lazadaVariants->isEmpty()) {
            return $items;
        }

        // Build a map of seller_sku => quantity from current ERP option values
        $erpOptionQty = [];
        $erpOptions = DB::table($pfx . 'product_option_value')
            ->where('product_id', (int) $product->product_id)
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->get(['product_option_value_id', 'sku', 'quantity']);

        foreach ($erpOptions as $opt) {
            $erpOptionQty[trim((string) $opt->sku)] = (int) $opt->quantity;
        }

        foreach ($lazadaVariants as $variant) {
            $skuId = $variant->sku_id ?? null;
            if (!$skuId) continue;

            $sellerSku = trim((string) ($variant->seller_sku ?? ''));

            // Match by seller_sku first (resilient to option value ID changes)
            if ($sellerSku !== '' && array_key_exists($sellerSku, $erpOptionQty)) {
                $qty = max(0, $erpOptionQty[$sellerSku]);
            } else {
                // Fallback to product_option_value_id if seller_sku didn't match
                $povId = $variant->product_option_value_id;
                if ($povId) {
                    $erpQty = DB::table($pfx . 'product_option_value')
                        ->where('product_option_value_id', $povId)
                        ->value('quantity');
                    $qty = max(0, (int) ($erpQty ?? 0));
                } elseif (!empty($erpOptionQty)) {
                    // Product has ERP option values but this Lazada variant couldn't be matched.
                    // Use 0 to avoid pushing the parent sum (which would overcount).
                    $qty = 0;
                    Log::warning('Lazada push-stock: unmatched variant, defaulting to 0', [
                        'product_id' => $product->product_id,
                        'lazada_seller_sku' => $sellerSku,
                        'lazada_sku_id' => $skuId,
                    ]);
                } else {
                    // No option values at all — simple product, use base product quantity
                    $qty = max(0, (int) ($product->quantity ?? 0));
                }
            }

            $items[] = [
                'sku_id'     => (int) $skuId,
                'seller_sku' => $sellerSku,
                'quantity'   => $qty,
            ];
        }

        return $items;
    }

    /**
     * Fetch product SKU details from Lazada API and cache them in lazada_product_variants.
     */
    private function fetchAndCacheLazadaVariants(LazadaProduct $listing, object $setting, LazadaClient $client, string $pfx): void
    {
        $itemId = (string) ($listing->lazada_item_id ?? '');
        if ($itemId === '') return;

        $apiPath = '/product/item/get';
        $params = [
            'app_key'      => (string) $setting->app_key,
            'sign_method'  => 'sha256',
            'timestamp'    => (string) round(microtime(true) * 1000),
            'access_token' => (string) $setting->access_token,
            'item_id'      => $itemId,
        ];
        $params['sign'] = $client->sign($apiPath, $params, (string) $setting->app_secret);

        $res = $client->get((string) $setting->region, $apiPath, $params);
        $body = $res['body'] ?? [];
        $data = $body['data'] ?? $body;

        $skus = data_get($data, 'skus') ?? data_get($data, 'Skus.Sku') ?? [];
        if (!is_array($skus)) return;

        // Build seller_sku => product_option_value_id map from ERP
        $skuToPovId = [];
        $erpOvs = DB::table($pfx . 'product_option_value')
            ->where('product_id', (int) $listing->product_id)
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->get(['product_option_value_id', 'sku']);
        foreach ($erpOvs as $ov) {
            $skuToPovId[trim((string) $ov->sku)] = (int) $ov->product_option_value_id;
        }

        foreach ($skus as $s) {
            $sellerSku = trim((string) ($s['SellerSku'] ?? $s['seller_sku'] ?? ''));
            $skuId = $s['SkuId'] ?? $s['sku_id'] ?? $s['skuId'] ?? null;
            $shopSku = $s['ShopSku'] ?? $s['shop_sku'] ?? null;

            if ($skuId === null || $skuId === '') continue;

            $povId = $skuToPovId[$sellerSku] ?? null;

            try {
                LazadaProductVariant::updateOrCreate(
                    ['lazada_product_id' => $listing->id, 'seller_sku' => $sellerSku],
                    [
                        'sku_id' => (int) $skuId,
                        'shop_sku' => $shopSku ? (string) $shopSku : null,
                        'product_option_value_id' => $povId,
                    ]
                );
            } catch (\Throwable $ex) {
                Log::warning("Failed to cache Lazada variant for listing {$listing->id}", [
                    'seller_sku' => $sellerSku,
                    'error' => $ex->getMessage(),
                ]);
            }
        }

        LazadaApiLog::safeCreate([
            'pack'            => 'lazada.product.item.get.cron',
            'method'          => 'GET',
            'api_path'        => $apiPath,
            'auth_required'   => true,
            'request_params'  => $params,
            'response_status' => (int) ($res['status'] ?? 0),
            'ok'              => (bool) ($res['ok'] ?? false),
            'response_body'   => $body,
            'user_id'         => null,
        ]);
    }

    /**
     * Build XML payload with multiple SKUs in a single request.
     */
    private function buildBatchStockXml(array $items): string
    {
        $skuXml = '';
        foreach ($items as $item) {
            $skuId = (int) $item['sku_id'];
            $qty = max(0, (int) $item['quantity']);
            $skuXml .= '<Sku>'
                . '<SkuId>' . $skuId . '</SkuId>'
                . '<SellableQuantity>' . $qty . '</SellableQuantity>'
                . '</Sku>';
        }

        return '<Request><Product><Skus>' . $skuXml . '</Skus></Product></Request>';
    }

    private function pushToLazada(LazadaClient $client, object $setting, string $apiPath, string $payloadXml): array
    {
        $rateKey = 'lazada_api_last_call:' . $setting->region . ':' . $setting->app_key;
        $lockKey = 'lazada_api_lock:' . $setting->region . ':' . $setting->app_key;
        $minIntervalMs = 500;

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
            if ($code === 'SellerCallLimit' || $code === 'ApiCallLimit') {
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
        ]);

        return $result;
    }

}
