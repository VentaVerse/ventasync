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

class TikTokPushStock extends Command implements \App\Integrations\Contracts\SteppableAutomation
{
    protected $signature = 'tiktok:push-stock';

    protected $description = 'Push ERP product quantities to TikTok Shop for all linked products';

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

        $pivotRows = TikTokProductGroupProduct::query()
            ->whereIn('tiktok_product_group_id', \Extensions\tiktok\Models\TikTokProductGroup::query()->select('id'))
            ->whereNotNull('tiktok_product_id')
            ->where('tiktok_product_id', '!=', '')
            ->get();

        if ($pivotRows->isEmpty()) {
            $this->info('No TikTok products linked.');
            $store->forceFill(['last_stock_push_at' => now()])->save();
            return 0;
        }

        $this->info("Pushing stock for {$pivotRows->count()} product(s)...");

        $c = [
            'app_key' => $appKey, 'app_secret' => $appSecret, 'token' => $token,
            'shop_cipher' => $shopCipher, 'warehouse_id' => $warehouseId,
        ];
        $results = app(\Extensions\tiktok\Services\TikTok\TikTokStockPricePush::class)
            ->push('stock', $c, $pivotRows, $pfx);
        \Extensions\tiktok\Services\TikTok\TikTokStockPricePush::recordOutcomes('stock', $results['outcomes'], $store);

        $totalOk = $results['ok'];
        $totalErr = $results['err'];
        $skipped = $results['skipped'];
        if ($totalErr > 0) {
            Log::warning('TikTok push-stock: ' . $totalErr . ' product(s) failed', ['last_error' => $results['last_error']]);
        }

        $store->forceFill(['last_stock_push_at' => now()])->save();

        $this->info("Done. Success: {$totalOk}, Failed: {$totalErr}, Skipped: {$skipped}."
            . \Extensions\tiktok\Services\TikTok\TikTokStockPricePush::ledgerClause($results));
        return $totalErr > 0 ? 1 : 0;
    }


    public function automationUnits(\App\Models\ScheduledJob $job): array
    {
        return ['label' => 'products', 'units' => \Extensions\tiktok\Services\TikTok\TikTokPushSteps::units()];
    }

    public function automationStep(\App\Models\ScheduledJob $job, array $units): array
    {
        return \Extensions\tiktok\Services\TikTok\TikTokPushSteps::step('stock', $units);
    }
}
