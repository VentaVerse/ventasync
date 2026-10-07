<?php

namespace Extensions\ventacart\Commands;

use Extensions\ventacart\Models\VentaCartProductLink;
use Extensions\ventacart\Models\VentaCartSetting;
use Extensions\ventacart\Services\VentaCart\VentaCartStockPricePush;
use Illuminate\Console\Command;

class VentaCartPushStock extends Command implements \App\Integrations\Contracts\SteppableAutomation
{
    protected $signature = 'ventacart:push-stock
        {--store= : Store ID (ventacart_settings.id). Omit to push for all enabled stores}';

    protected $description = 'Push ERP product quantities to all linked VentaCart products';

    public function handle(): int
    {
        $storeId = $this->option('store');

        if ($storeId) {
            $settings = VentaCartSetting::where('id', (int) $storeId)->where('enabled', true)->get();
        } else {
            $settings = VentaCartSetting::where('enabled', true)->get();
        }

        if ($settings->isEmpty()) {
            $this->error('No enabled VentaCart stores found.');
            return 1;
        }

        foreach ($settings as $setting) {
            $this->info("Pushing stock for: {$setting->store_name} (#{$setting->id})");

            $pids = VentaCartProductLink::where('ventacart_setting_id', $setting->id)
                ->whereNotNull('ventacart_product_id')
                ->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all();

            if ($pids === []) {
                $this->info('  No linked products. Skipping.');
                continue;
            }

            $r = VentaCartStockPricePush::for($setting)->push($pids, true, false);
            VentaCartStockPricePush::recordOutcomes((int) $setting->id, $r['outcomes']);

            $setting->update(['last_stock_push_at' => now()]);

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
        return \Extensions\ventacart\Services\VentaCart\VentaCartPushSteps::step('stock', $units);
    }
}
