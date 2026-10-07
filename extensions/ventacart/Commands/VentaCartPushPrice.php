<?php

namespace Extensions\ventacart\Commands;

use Extensions\ventacart\Models\VentaCartProductLink;
use Extensions\ventacart\Models\VentaCartSetting;
use Extensions\ventacart\Services\VentaCart\VentaCartStockPricePush;
use Illuminate\Console\Command;

class VentaCartPushPrice extends Command implements \App\Integrations\Contracts\SteppableAutomation
{
    protected $signature = 'ventacart:push-price
        {--store= : Store ID (ventacart_settings.id). Omit to push for all enabled stores}';

    protected $description = 'Push catalog prices to every product linked to a VentaCart store';

    public function handle(): int
    {
        $stores = VentaCartSetting::query()->where('enabled', true);
        if ($this->option('store')) {
            $stores->where('id', (int) $this->option('store'));
        }
        $stores = $stores->get();
        if ($stores->isEmpty()) {
            $this->error('No enabled VentaCart stores found.');

            return 1;
        }

        foreach ($stores as $setting) {
            $this->info("Pushing prices for: {$setting->store_name} (#{$setting->id})");
            $ids = VentaCartProductLink::query()->where('ventacart_setting_id', $setting->id)->whereNotNull('ventacart_product_id')
                ->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
            if ($ids === []) {
                $this->info('  No linked products. Skipping.');
                continue;
            }
            $r = VentaCartStockPricePush::for($setting)->push($ids, false, true);
            VentaCartStockPricePush::recordOutcomes((int) $setting->id, $r['outcomes']);
            $this->info("  Done. OK: {$r['ok']}, Failed: {$r['failed']}, Skipped: {$r['skipped']}");
        }

        return 0;
    }

    public function automationUnits(\App\Models\ScheduledJob $job): array
    {
        return ['label' => 'products', 'units' => \Extensions\ventacart\Services\VentaCart\VentaCartPushSteps::units($job)];
    }

    public function automationStep(\App\Models\ScheduledJob $job, array $units): array
    {
        return \Extensions\ventacart\Services\VentaCart\VentaCartPushSteps::step('price', $units);
    }
}
