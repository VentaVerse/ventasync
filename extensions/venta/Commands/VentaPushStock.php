<?php

namespace Extensions\venta\Commands;

use Extensions\venta\Models\VentaProductLink;
use Extensions\venta\Models\VentaSetting;
use Extensions\venta\Services\Venta\VentaClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VentaPushStock extends Command
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

        $pfx = (string) config('catalog.prefix');

        foreach ($settings as $setting) {
            $this->info("Pushing stock for: {$setting->store_name} (#{$setting->id})");

            $client = new VentaClient($setting);
            $links = VentaProductLink::where('venta_setting_id', $setting->id)
                ->whereNotNull('venta_product_id')
                ->get();

            if ($links->isEmpty()) {
                $this->info('  No linked products. Skipping.');
                continue;
            }

            $productIds = $links->pluck('product_id')->unique()->toArray();

            $erpProducts = DB::table($pfx . 'product')
                ->whereIn('product_id', $productIds)
                ->get(['product_id', 'sku', 'quantity', 'status'])
                ->keyBy('product_id');

            $erpOptions = DB::table($pfx . 'product_option_value')
                ->whereIn('product_id', $productIds)
                ->whereNotNull('sku')
                ->where('sku', '!=', '')
                ->get(['product_id', 'sku', 'quantity'])
                ->groupBy(fn($r) => (int) $r->product_id);

            $ok = 0;
            $err = 0;
            $skip = 0;

            foreach ($links as $link) {
                $product = $erpProducts->get($link->product_id);
                if (!$product || (int) $product->status === 0) {
                    $skip++;
                    continue;
                }

                $sku = $link->sku ?: $product->sku;

                $variants = $erpOptions->get($link->product_id);
                if ($variants && $variants->count() > 0) {
                    $allOk = true;
                    foreach ($variants as $ov) {
                        $result = $client->pushVariantStock(trim($ov->sku), max(0, (int) $ov->quantity));
                        if (!$result['ok']) $allOk = false;
                    }
                    $client->pushStock($sku, max(0, (int) $product->quantity));
                    $allOk ? $ok++ : $err++;
                } else {
                    $result = $client->pushStock($sku, max(0, (int) $product->quantity));
                    $result['ok'] ? $ok++ : $err++;
                }

                usleep(200000); // 200ms rate limit
            }

            $this->info("  Done. OK: {$ok}, Failed: {$err}, Skipped: {$skip}");
        }

        return 0;
    }
}
