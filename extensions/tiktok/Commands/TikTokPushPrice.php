<?php

namespace Extensions\tiktok\Commands;

use Extensions\tiktok\Models\TikTokListing;
use Extensions\tiktok\Models\TikTokProductGroup;
use Extensions\tiktok\Models\TikTokProductGroupProduct;
use Extensions\tiktok\Models\TikTokSetting;
use Extensions\tiktok\Services\TikTok\TikTokStockPricePush;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TikTokPushPrice extends Command implements \App\Integrations\Contracts\SteppableAutomation
{
    protected $signature = 'tiktok:push-price';

    protected $description = 'Push catalog prices, through each listing\'s price rule, to every product linked to TikTok Shop';

    public function handle(): int
    {
        $stores = TikTokSetting::enabledStores();
        if ($stores->isEmpty()) {
            $this->error('No enabled TikTok store. Configure TikTok settings first.');

            return 1;
        }

        $worst = 0;
        foreach ($stores as $store) {
            $label = $store->store_name ?: ('#' . $store->id);
            $this->info("=== TikTok store: {$label} ===");
            app()->instance('tiktok.route-store', $store);
            try {
                $worst = max($worst, $this->handleStore($store));
            } catch (\Throwable $e) {
                $this->error("Store {$label} failed: " . $e->getMessage());
                Log::error('TikTok push-price: store failed', ['store' => $store->id, 'error' => $e->getMessage()]);
                $worst = 1;
            }
        }
        app()->forgetInstance('tiktok.route-store');

        return $worst;
    }

    private function handleStore(TikTokSetting $raw): int
    {
        $s = $raw->decrypted();
        $sandbox = $raw->mode === 'sandbox';
        $appKey = $sandbox ? ($s->sandbox_app_key ?? '') : ($s->app_key ?? '');
        $appSecret = $sandbox ? ($s->sandbox_app_secret ?? '') : ($s->app_secret ?? '');
        $token = $sandbox ? ($s->sandbox_access_token ?? '') : ($s->access_token ?? '');
        $shopCipher = $sandbox ? ($raw->sandbox_shop_cipher ?? '') : ($raw->shop_cipher ?? '');
        if (! $appKey || ! $appSecret || ! $token) {
            $this->error('Missing TikTok credentials/token.');

            return 1;
        }
        $expiresAt = $sandbox ? $raw->sandbox_expires_at : $raw->expires_at;
        if ($expiresAt && $expiresAt->isPast()) {
            $this->warn('TikTok access token expired. Refresh it first.');

            return 1;
        }

        $pivotRows = TikTokProductGroupProduct::query()
            ->whereIn('tiktok_product_group_id', TikTokProductGroup::query()->select('id'))
            ->whereNotNull('tiktok_product_id')
            ->where('tiktok_product_id', '!=', '')
            ->get();
        if ($pivotRows->isEmpty()) {
            $this->info('No TikTok products linked.');

            return 0;
        }

        $pfx = (string) config('catalog.prefix');
        $c = [
            'app_key' => $appKey, 'app_secret' => $appSecret, 'token' => $token,
            'shop_cipher' => $shopCipher, 'warehouse_id' => $raw->warehouse_id ?: null,
        ];
        $this->info("Pushing prices for {$pivotRows->count()} product(s)...");
        $results = app(TikTokStockPricePush::class)->push('price', $c, $pivotRows, $pfx, self::priceRule($pivotRows));
        TikTokStockPricePush::recordOutcomes('price', $results['outcomes'], $raw);

        if ($results['err'] > 0) {
            Log::warning('TikTok push-price: ' . $results['err'] . ' product(s) failed', ['last_error' => $results['last_error']]);
        }
        $this->info("Done. Success: {$results['ok']}, Failed: {$results['err']}, Skipped: {$results['skipped']}." . TikTokStockPricePush::ledgerClause($results));

        return $results['err'] > 0 ? 1 : 0;
    }

    public static function priceRule(iterable $pivots): \Closure
    {
        $pivots = collect($pivots);
        $pids = $pivots->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        $listings = TikTokListing::query()->whereIn('product_id', $pids ?: [0])->get()->keyBy('product_id');
        $store = TikTokSetting::defaultStore();
        $groupByProduct = TikTokProductGroupProduct::query()->whereIn('product_id', $pids ?: [0])
            ->whereIn('tiktok_product_group_id', TikTokProductGroup::query()->forStore($store)->select('id'))
            ->orderBy('id')->get()
            ->groupBy('product_id')->map(fn ($rows) => (int) $rows->first()->tiktok_product_group_id);
        $groupIds = $pivots->pluck('tiktok_product_group_id')->filter()->merge($groupByProduct->values())->unique()->all();
        $groups = TikTokProductGroup::query()->forStore($store)->whereIn('id', $groupIds ?: [0])->get()->keyBy('id');

        return function (float $base, object $pivot) use ($listings, $groups, $groupByProduct): float {
            $pid = (int) $pivot->product_id;
            $listing = $listings->get($pid);
            if ($listing && ($listing->markup_percent !== null || $listing->markup_fixed !== null)) {
                return $listing->priceFor($base);
            }
            $group = $groups->get((int) ($pivot->tiktok_product_group_id ?? 0)) ?? $groups->get((int) ($groupByProduct->get($pid) ?? 0));

            return $group ? $group->applyMarkup($base) : $base;
        };
    }

    public function automationUnits(\App\Models\ScheduledJob $job): array
    {
        return ['label' => 'products', 'units' => \Extensions\tiktok\Services\TikTok\TikTokPushSteps::units()];
    }

    public function automationStep(\App\Models\ScheduledJob $job, array $units): array
    {
        return \Extensions\tiktok\Services\TikTok\TikTokPushSteps::step('price', $units);
    }
}
