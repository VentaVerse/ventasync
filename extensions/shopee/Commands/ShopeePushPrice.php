<?php

namespace Extensions\Shopee\Commands;

use Extensions\Shopee\Models\ShopeeListing;
use Extensions\Shopee\Models\ShopeeListingLink;
use Extensions\Shopee\Models\ShopeeStore;
use Extensions\Shopee\Services\Shopee\ShopeeStockPricePush;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShopeePushPrice extends Command implements \App\Channels\Contracts\SteppableAutomation
{
    protected $signature = 'shopee:push-price';

    protected $description = 'Push catalog prices, through each listing\'s price rule, to every product linked to Shopee';

    public function handle(): int
    {
        $stores = ShopeeStore::query()->where('enabled', true)->orderBy('id')->get();
        if ($stores->isEmpty()) {
            $this->error('No enabled Shopee store. Configure Shopee settings first.');

            return 1;
        }

        $worst = 0;
        foreach ($stores as $store) {
            $label = $store->store_name ?: ('#' . $store->id);
            $this->info("=== Shopee store: {$label} ===");
            app()->instance('shopee.route-store', $store);
            try {
                $worst = max($worst, $this->pushStore($store));
            } catch (\Throwable $e) {
                $this->error("Store {$label} failed: " . $e->getMessage());
                Log::error('Shopee push-price: store failed', ['store' => $store->id, 'error' => $e->getMessage()]);
                $worst = 1;
            }
        }
        app()->forgetInstance('shopee.route-store');

        return $worst;
    }

    private function pushStore(ShopeeStore $store): int
    {
        $setting = $store->decrypted();
        $auth = ShopeeStore::activeAuth($setting);
        if (! $auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            $this->error("Missing Shopee {$modeLabel} credentials/token for this store.");

            return 1;
        }

        $links = ShopeeListingLink::forStore($store)->get();
        if ($links->isEmpty()) {
            $this->warn('No Shopee product links found.');

            return 0;
        }
        $enabledIds = DB::table('products')
            ->whereIn('product_id', $links->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->all())
            ->where('status', 1)
            ->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        $skipped = $links->count() - ($links = $links->filter(fn ($l) => in_array((int) $l->product_id, $enabledIds, true))->values())->count();

        $this->info("Pushing prices for {$links->count()} link(s)...");
        $results = app(ShopeeStockPricePush::class)->push('price', $auth, $links, self::priceRule($enabledIds));

        if ($results['err'] > 0) {
            Log::warning('Shopee push-price: ' . $results['err'] . ' model(s) failed', ['last_error' => $results['last_error']]);
        }
        $this->info("Done. Success: {$results['ok']}, Failed: {$results['err']}, Skipped: {$skipped}." . ShopeeStockPricePush::ledgerClause($results));

        return $results['err'] > 0 ? 1 : 0;
    }

    public static function priceRule(array $productIds): \Closure
    {
        $listings = ShopeeListing::query()->whereIn('product_id', $productIds ?: [0])->get()->keyBy('product_id');
        $withVariations = ShopeeListing::withVariations($productIds);

        return function (int $productId, float $base) use ($listings, $withVariations): float {
            $listing = $listings->get($productId) ?? (new ShopeeListing())->forceFill(['product_id' => $productId]);

            return (float) $listing->itemPriceFor($base, isset($withVariations[$productId]));
        };
    }

    public function automationUnits(\App\Models\ScheduledJob $job): array
    {
        return ['label' => 'products', 'units' => \Extensions\Shopee\Services\Shopee\ShopeePushSteps::units()];
    }

    public function automationStep(\App\Models\ScheduledJob $job, array $units): array
    {
        return \Extensions\Shopee\Services\Shopee\ShopeePushSteps::step('price', $units);
    }
}
