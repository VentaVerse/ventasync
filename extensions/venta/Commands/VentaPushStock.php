<?php

namespace Extensions\venta\Commands;

use Extensions\venta\Models\VentaProductLink;
use Extensions\venta\Models\VentaSetting;
use Extensions\venta\Services\Venta\VentaStockPricePush;
use Illuminate\Console\Command;

class VentaPushStock extends Command implements \App\Integrations\Contracts\SteppableAutomation
{
    protected $signature = 'venta:push-stock
        {--store= : Store ID (venta_settings.id). Omit to push for all enabled stores}';

    protected $description = 'Push ERP product quantities to all linked Venta products';

    public function handle(): int
    {
        $storeId = $this->option('store');

        if ($storeId) {
            $settings = VentaSetting::where('id', (int) $storeId)->where('enabled', true)->get();
        } else {
            $settings = VentaSetting::where('enabled', true)->get();
        }

        if ($settings->isEmpty()) {
            $this->error('No enabled Venta stores found.');
            return 1;
        }

        foreach ($settings as $setting) {
            $this->info("Pushing stock for: {$setting->store_name} (#{$setting->id})");

            $pids = VentaProductLink::where('venta_setting_id', $setting->id)
                ->whereNotNull('venta_product_id')
                ->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all();

            if ($pids === []) {
                $this->info('  No linked products. Skipping.');
                continue;
            }

            /* The one sender every Venta stock and price path uses: only the
             * variations this store sells go up, and one the store's item
             * lacks is named on its listing rather than failing the product. */
            $r = VentaStockPricePush::for($setting)->push($pids, true, false);
            VentaStockPricePush::recordOutcomes((int) $setting->id, $r['outcomes']);

            // Stamped even when some rows failed: it answers when this last ran.
            $setting->update(['last_stock_push_at' => now()]);

            $this->info("  Done. OK: {$r['ok']}, Failed: {$r['failed']}, Skipped: {$r['skipped']}");
        }

        return 0;
    }

    /** Run now walks this push ten products per request (SteppableAutomation, 2026-09-07). */
    public function automationUnits(\App\Models\ScheduledJob $job): array
    {
        return ['label' => 'products', 'units' => \Extensions\venta\Services\Venta\VentaPushSteps::units($job)];
    }

    public function automationStep(\App\Models\ScheduledJob $job, array $units): array
    {
        return \Extensions\venta\Services\Venta\VentaPushSteps::step('stock', $units);
    }
}
