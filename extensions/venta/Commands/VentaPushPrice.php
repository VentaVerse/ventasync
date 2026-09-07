<?php

namespace Extensions\venta\Commands;

use Extensions\venta\Models\VentaProductLink;
use Extensions\venta\Models\VentaSetting;
use Extensions\venta\Services\Venta\VentaStockPricePush;
use Illuminate\Console\Command;

/**
 * The "Push Price" automation (the owner, 2026-09-07). The old
 * stock-and-price row sent stock only; price is its own row now, on every
 * channel. Every linked product on the store, or on every enabled store,
 * through the same sender the Push price button uses.
 */
class VentaPushPrice extends Command
{
    protected $signature = 'venta:push-price
        {--store= : Store ID (venta_settings.id). Omit to push for all enabled stores}';

    protected $description = 'Push catalog prices to every product linked to a Venta store';

    public function handle(): int
    {
        $stores = VentaSetting::query()->where('enabled', true);
        if ($this->option('store')) {
            $stores->where('id', (int) $this->option('store'));
        }
        $stores = $stores->get();
        if ($stores->isEmpty()) {
            $this->error('No enabled Venta stores found.');

            return 1;
        }

        foreach ($stores as $setting) {
            $this->info("Pushing prices for: {$setting->store_name} (#{$setting->id})");
            $ids = VentaProductLink::query()->where('venta_setting_id', $setting->id)->whereNotNull('venta_product_id')
                ->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
            if ($ids === []) {
                $this->info('  No linked products. Skipping.');
                continue;
            }
            $r = VentaStockPricePush::for($setting)->push($ids, false, true);
            VentaStockPricePush::recordOutcomes((int) $setting->id, $r['outcomes']);
            $this->info("  Done. OK: {$r['ok']}, Failed: {$r['failed']}, Skipped: {$r['skipped']}");
        }

        return 0;
    }
}
