<?php

namespace Extensions\Lazada\Commands;

use Extensions\Lazada\Models\LazadaListing;
use Extensions\Lazada\Models\LazadaStore;
use Extensions\Lazada\Services\Lazada\LazadaInheritedSettings;
use Extensions\Lazada\Services\Lazada\LazadaPushPayload;
use Extensions\Lazada\Services\Lazada\LazadaStockPricePush;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class LazadaPushPrice extends Command implements \App\Channels\Contracts\SteppableAutomation
{
    protected $signature = 'lazada:push-price';

    protected $description = 'Push catalog prices, through each listing\'s price rule, to every product linked to Lazada';

    public function handle(): int
    {
        $stores = LazadaStore::enabledStores();
        if ($stores->isEmpty()) {
            $this->error('No enabled Lazada store. Configure Lazada settings first.');

            return 1;
        }

        $worst = 0;
        foreach ($stores as $store) {
            $label = $store->store_name ?: ('#' . $store->id);
            $this->info("=== Lazada store: {$label} ===");
            app()->instance('lazada.route-store', $store);
            try {
                $worst = max($worst, $this->handleStore($store));
            } catch (\Throwable $e) {
                $this->error("Store {$label} failed: " . $e->getMessage());
                Log::error('Lazada push-price: store failed', ['store' => $store->id, 'error' => $e->getMessage()]);
                $worst = 1;
            }
        }
        app()->forgetInstance('lazada.route-store');

        return $worst;
    }

    private function handleStore(LazadaStore $store): int
    {
        $setting = $store->decrypted();
        $creds = LazadaStore::activeCredentials($setting);
        if (! $setting || ! $setting->region || ! $creds['app_key'] || ! $creds['app_secret'] || ! $creds['access_token']) {
            $this->error('Missing Lazada credentials/token. Configure Lazada settings first.');

            return 1;
        }

        $listings = LazadaListing::query()
            ->where('product_id', '>', 0)
            ->whereNotNull('lazada_item_id')
            ->where('lazada_item_id', '!=', '')
            ->whereNull('unlinked_at')
            ->get();
        if ($listings->isEmpty()) {
            $this->warn('No Lazada listings linked to an item.');

            return 0;
        }
        $this->info("Pushing prices for {$listings->count()} listing(s)...");
        $results = app(LazadaStockPricePush::class)->push('price', $setting, $creds, $listings, self::priceRule($listings));

        if ($results['err'] > 0) {
            Log::warning('Lazada push-price: ' . $results['err'] . ' listing(s) failed', ['last_error' => $results['last_error']]);
        }
        $this->info("Done. Success: {$results['ok']}, Failed: {$results['err']}, Skipped: {$results['skipped']}." . LazadaStockPricePush::ledgerClause($results));

        return $results['err'] > 0 ? 1 : 0;
    }

    public static function priceRule(iterable $listings): \Closure
    {
        $listings = collect($listings);
        $groups = app(LazadaInheritedSettings::class)->forProducts($listings->pluck('product_id')->map(fn ($v) => (int) $v)->all());
        $rules = [];
        foreach ($listings as $l) {
            $pid = (int) $l->product_id;
            $fixed = $l->markup_fixed;
            $pct = $l->markup_percent;
            if ($fixed === null && $pct === null && isset($groups[$pid])) {
                $fixed = $groups[$pid]['markup_fixed'];
                $pct = $groups[$pid]['markup_percent'];
            }
            $rules[$pid] = [$fixed, $pct];
        }

        return function (int $productId, float $base) use ($rules): float {
            [$fixed, $pct] = $rules[$productId] ?? [null, null];

            return LazadaPushPayload::computeFinalPrice($base, (float) ($fixed ?? 0), (float) ($pct ?? 0));
        };
    }

    public function automationUnits(\App\Models\ScheduledJob $job): array
    {
        return ['label' => 'products', 'units' => \Extensions\Lazada\Services\Lazada\LazadaPushSteps::units()];
    }

    public function automationStep(\App\Models\ScheduledJob $job, array $units): array
    {
        return \Extensions\Lazada\Services\Lazada\LazadaPushSteps::step('price', $units);
    }
}
