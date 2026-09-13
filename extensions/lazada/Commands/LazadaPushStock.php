<?php

namespace Extensions\lazada\Commands;

use Extensions\lazada\Models\LazadaApiLog;
use Extensions\lazada\Models\LazadaProduct;
use Extensions\lazada\Models\LazadaProductVariant;
use Extensions\lazada\Models\LazadaSetting;
use Extensions\lazada\Services\Lazada\LazadaClient;
use Extensions\lazada\Services\LazadaStockPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LazadaPushStock extends Command implements \App\Integrations\Contracts\SteppableAutomation
{
    protected $signature = 'lazada:push-stock
        {--product-ids= : Comma-separated Lazada listing IDs to push (lazada_products.id)}';

    protected $description = 'Push ERP product quantities to Lazada for all linked products';

    public function handle(LazadaClient $client): int
    {
        $stores = \Extensions\lazada\Models\LazadaSetting::enabledStores();
        if ($stores->isEmpty()) { $this->error('No enabled Lazada store. Configure Lazada settings first.'); return 1; }
        $worst = 0;
        foreach ($stores as $store) {
            $label = $store->store_name ?: ('#' . $store->id);
            $this->info("=== Lazada store: {$label} ===");
            app()->instance('lazada.route-store', $store); // bind: the model trait now scopes every query + stamps writes to THIS store
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
            $store->forceFill(['last_stock_push_at' => now()])->save();
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

        $store->forceFill(['last_stock_push_at' => now()])->save();

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
                app(\Extensions\lazada\Services\Lazada\LazadaItemCache::class)->fetchAndCacheVariants($listing, $setting, $client, 'lazada.product.item.get.cron');
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

            // Combinations AND option values (Phase F, #184): POV alone
            // meant a multi-option product's cache never went stale here.
            // Only what this store sells can be missing from its cache.
            $erpSkus = \App\Integrations\Listings\ListingVariations::sold('lazada', (int) ($listing->lazada_setting_id ?? 0), [(int) $listing->product_id], $pfx)[(int) $listing->product_id] ?? [];

            if (empty($erpSkus) || empty(array_diff($erpSkus, $cachedSkus))) {
                continue; // Cache is up-to-date
            }

            app(\Extensions\lazada\Services\Lazada\LazadaItemCache::class)->fetchAndCacheVariants($listing, $setting, $client, 'lazada.product.item.get.cron');
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
        // Delegates to LazadaStockPushService — combinations-aware. See that
        // class for why this is shared with the UI controller's push path.
        $service = app(LazadaStockPushService::class);
        $rows = $service->buildPushItems($listing, $product, $pfx);
        /* Orphan variants (on Lazada, gone from the catalogue) are skipped,
         * not zeroed (Phase F, #184) - a silent zero is a lie the shop
         * wears until someone notices the missing stock. */
        $hasVariations = !empty(\App\Integrations\Push\PushLedger::erpVariationSkus([(int) $listing->product_id], $pfx)[(int) $listing->product_id] ?? []);
        if ($hasVariations) {
            $rows = array_values(array_filter($rows, fn ($r) => $r['matched'] ?? true));
        }

        // Cron only consumes sku_id/seller_sku/quantity. Strip the price field.
        return array_map(function ($r) {
            return [
                'sku_id'     => $r['sku_id'],
                'seller_sku' => $r['seller_sku'],
                'quantity'   => $r['quantity'],
            ];
        }, $rows);
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
        $creds = LazadaSetting::activeCredentials($setting);

        $rateKey = 'lazada_api_last_call:' . $setting->region . ':' . $creds['app_key'];
        $lockKey = 'lazada_api_lock:' . $setting->region . ':' . $creds['app_key'];
        $minIntervalMs = 500;

        $params = [
            'app_key'      => (string) $creds['app_key'],
            'sign_method'  => 'sha256',
            'timestamp'    => (string) round(microtime(true) * 1000),
            'access_token' => (string) $creds['access_token'],
            'payload'      => $payloadXml,
        ];

        $callOnce = function () use ($client, $setting, $apiPath, &$params, $creds) {
            $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);
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


    /** Run now walks this push ten products per request (SteppableAutomation, 2026-09-07). */
    public function automationUnits(\App\Models\ScheduledJob $job): array
    {
        return ['label' => 'products', 'units' => \Extensions\lazada\Services\Lazada\LazadaPushSteps::units()];
    }

    public function automationStep(\App\Models\ScheduledJob $job, array $units): array
    {
        return \Extensions\lazada\Services\Lazada\LazadaPushSteps::step('stock', $units);
    }
}
