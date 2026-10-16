<?php

namespace Extensions\Shopee\Commands;

use Extensions\Shopee\Models\ShopeeApiLog;
use Extensions\Shopee\Models\ShopeeListingLink;
use Extensions\Shopee\Models\ShopeeStore;
use Extensions\Shopee\Services\Shopee\ShopeeClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShopeePushStock extends Command implements \App\Channels\Contracts\SteppableAutomation
{
    protected $signature = 'shopee:push-stock';

    protected $description = 'Push catalog quantities to Shopee for all linked products';

    public function handle(ShopeeClient $client): int
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
            try {
                $worst = max($worst, $this->pushStore($store));
            } catch (\Throwable $e) {
                $this->error("Store {$label} failed: " . $e->getMessage());
                Log::error('Shopee push-stock: store failed', ['store' => $store->id, 'error' => $e->getMessage()]);
                $worst = 1;
            }
        }

        return $worst;
    }

    private function pushStore(ShopeeStore $store): int
    {
        $setting = $store->decrypted();
        $auth = ShopeeStore::activeAuth($setting);
        if (!$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            $this->error("Missing Shopee {$modeLabel} credentials/token for this store.");
            return 1;
        }

        $links = ShopeeListingLink::forStore($store)->get();

        if ($links->isEmpty()) {
            $this->warn('No Shopee product links found.');
            return 0;
        }

        $this->info("Pushing stock for {$links->count()} link(s)...");

        $enabledIds = DB::table('products')
            ->whereIn('product_id', $links->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->all())
            ->where('status', 1)
            ->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        $totalSkipped = $links->count() - ($links = $links->filter(fn ($l) => in_array((int) $l->product_id, $enabledIds, true))->values())->count();

        $results = app(\Extensions\Shopee\Services\Shopee\ShopeeStockPricePush::class)
            ->push('stock', $auth, $links);

        $totalOk = $results['ok'];
        $totalErr = $results['err'];
        if ($totalErr > 0) {
            Log::warning('Shopee push-stock: ' . $totalErr . ' model(s) failed', ['last_error' => $results['last_error']]);
        }

        $ledger = \Extensions\Shopee\Services\Shopee\ShopeeStockPricePush::ledgerClause($results);
        $this->info("Done. Success: {$totalOk}, Failed: {$totalErr}, Skipped: {$totalSkipped}." . $ledger);

        $store->forceFill(['last_stock_push_at' => now()])->save();

        return $totalErr > 0 ? 1 : 0;
    }


    public function automationUnits(\App\Models\ScheduledJob $job): array
    {
        return ['label' => 'products', 'units' => \Extensions\Shopee\Services\Shopee\ShopeePushSteps::units()];
    }

    public function automationStep(\App\Models\ScheduledJob $job, array $units): array
    {
        return \Extensions\Shopee\Services\Shopee\ShopeePushSteps::step('stock', $units);
    }
}
