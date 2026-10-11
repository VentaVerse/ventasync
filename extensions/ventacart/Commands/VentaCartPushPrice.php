<?php

namespace Extensions\VentaCart\Commands;

use Extensions\VentaCart\Models\VentaCartListingLink;
use Extensions\VentaCart\Models\VentaCartStore;
use Extensions\VentaCart\Services\VentaCart\VentaCartStockPricePush;
use Illuminate\Console\Command;

class VentaCartPushPrice extends Command implements \App\Channels\Contracts\SteppableAutomation
{
    protected $signature = 'ventacart:push-price
        {--store= : Store ID (ch_ventacart_stores.id). Omit to push for all enabled stores}';

    protected $description = 'Push catalog prices to every product linked to a VentaCart store';

    public function handle(): int
    {
        $stores = VentaCartStore::query()->where('enabled', true);
        if ($this->option('store')) {
            $stores->where('id', (int) $this->option('store'));
        }
        $stores = $stores->get();
        if ($stores->isEmpty()) {
            $this->error('No enabled VentaCart stores found.');

            return 1;
        }

        foreach ($stores as $store) {
            $this->info("Pushing prices for: {$store->store_name} (#{$store->id})");
            $ids = VentaCartListingLink::query()->where('store_id', $store->id)->whereNotNull('ventacart_product_id')
                ->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
            if ($ids === []) {
                $this->info('  No linked products. Skipping.');
                continue;
            }
            $r = VentaCartStockPricePush::for($store)->push($ids, false, true);
            VentaCartStockPricePush::recordOutcomes((int) $store->id, $r['outcomes']);
            $this->info("  Done. OK: {$r['ok']}, Failed: {$r['failed']}, Skipped: {$r['skipped']}");
        }

        return 0;
    }

    public function automationUnits(\App\Models\ScheduledJob $job): array
    {
        return ['label' => 'products', 'units' => \Extensions\VentaCart\Services\VentaCart\VentaCartPushSteps::units($job)];
    }

    public function automationStep(\App\Models\ScheduledJob $job, array $units): array
    {
        return \Extensions\VentaCart\Services\VentaCart\VentaCartPushSteps::step('price', $units);
    }
}
