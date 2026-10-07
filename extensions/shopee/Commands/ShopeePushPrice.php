<?php

namespace Extensions\shopee\Commands;

use Extensions\shopee\Models\ShopeeListing;
use Extensions\shopee\Models\ShopeeProductLink;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\Services\Shopee\ShopeeInheritedSettings;
use Extensions\shopee\Services\Shopee\ShopeeStockPricePush;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShopeePushPrice extends Command implements \App\Integrations\Contracts\SteppableAutomation
{
    protected $signature = 'shopee:push-price';

    protected $description = 'Push catalog prices, through each listing\'s price rule, to every product linked to Shopee';

    public function handle(): int
    {
        $stores = ShopeeSetting::query()->where('enabled', true)->orderBy('id')->get();
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

    private function pushStore(ShopeeSetting $store): int
    {
        $setting = $store->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (! $auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            $this->error("Missing Shopee {$modeLabel} credentials/token for this store.");

            return 1;
        }

        $links = ShopeeProductLink::forStore($store)->get();
        if ($links->isEmpty()) {
            $this->warn('No Shopee product links found.');

            return 0;
        }

        $pfx = (string) config('catalog.prefix');
        $enabledIds = DB::table($pfx . 'product')
            ->whereIn('product_id', $links->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->all())
            ->where('status', 1)
            ->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        $skipped = $links->count() - ($links = $links->filter(fn ($l) => in_array((int) $l->product_id, $enabledIds, true))->values())->count();

        $this->info("Pushing prices for {$links->count()} link(s)...");
        $results = app(ShopeeStockPricePush::class)->push('price', $auth, $links, $pfx, self::priceRule($enabledIds));

        if ($results['err'] > 0) {
            Log::warning('Shopee push-price: ' . $results['err'] . ' model(s) failed', ['last_error' => $results['last_error']]);
        }
        $this->info("Done. Success: {$results['ok']}, Failed: {$results['err']}, Skipped: {$skipped}." . ShopeeStockPricePush::ledgerClause($results));

        return $results['err'] > 0 ? 1 : 0;
    }

    public static function priceRule(array $productIds): \Closure
    {
        $listings = ShopeeListing::query()->whereIn('product_id', $productIds ?: [0])->get()->keyBy('product_id');
        $inherit = app(ShopeeInheritedSettings::class);
        $groups = $inherit->forProducts($productIds);
        $withVariations = ShopeeListing::withVariations($productIds);

        return function (int $productId, float $base) use ($listings, $groups, $inherit, $withVariations): float {
            $listing = $listings->get($productId) ?? (new ShopeeListing())->forceFill(['product_id' => $productId]);
            $filled = $inherit->fill($listing, $groups[$productId] ?? null)['listing'];

            return (float) $filled->itemPriceFor($base, isset($withVariations[$productId]));
        };
    }

    public function automationUnits(\App\Models\ScheduledJob $job): array
    {
        return ['label' => 'products', 'units' => \Extensions\shopee\Services\Shopee\ShopeePushSteps::units()];
    }

    public function automationStep(\App\Models\ScheduledJob $job, array $units): array
    {
        return \Extensions\shopee\Services\Shopee\ShopeePushSteps::step('price', $units);
    }
}
