<?php

namespace Extensions\Lazada\Services\Lazada;

use Extensions\Lazada\Commands\LazadaPushPrice;
use Extensions\Lazada\Models\LazadaListing;
use Extensions\Lazada\Models\LazadaStore;

final class LazadaPushSteps
{
    public static function units(): array
    {
        $units = [];
        foreach (LazadaStore::enabledStores() as $store) {
            app()->instance('lazada.route-store', $store);
            try {
                $pids = LazadaListing::query()->where('product_id', '>', 0)->whereNotNull('lazada_item_id')->where('lazada_item_id', '!=', '')->whereNull('unlinked_at')
                    ->orderBy('product_id')->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
            } finally {
                app()->forgetInstance('lazada.route-store');
            }
            foreach ($pids as $pid) {
                $units[] = $store->id . ':' . $pid;
            }
        }

        return $units;
    }

    public static function step(string $kind, array $units): array
    {
        $byStore = [];
        foreach ($units as $u) {
            [$sid, $pid] = array_pad(explode(':', (string) $u, 2), 2, 0);
            $byStore[(int) $sid][] = (int) $pid;
        }
        $ok = 0;
        $failed = 0;
        $notes = [];
        foreach ($byStore as $storeId => $pids) {
            $store = LazadaStore::query()->find($storeId);
            if (! $store) {
                $failed += count($pids);
                continue;
            }
            app()->instance('lazada.route-store', $store);
            try {
                $setting = $store->decrypted();
                $creds = LazadaStore::activeCredentials($setting);
                if (! $setting || ! $setting->region || ! $creds['app_key'] || ! $creds['app_secret'] || ! $creds['access_token']) {
                    return ['error' => 'Store ' . ($store->store_name ?: '#' . $store->id) . ' is not connected to Lazada.'];
                }
                $listings = LazadaListing::query()->whereIn('product_id', $pids)->whereNotNull('lazada_item_id')->where('lazada_item_id', '!=', '')->get();
                $results = app(LazadaStockPricePush::class)->push($kind, $setting, $creds, $listings, $kind === 'price' ? LazadaPushPrice::priceRule($listings) : null);
                foreach ($pids as $pid) {
                    $outcome = $results['outcomes'][$pid] ?? null;
                    if ($outcome !== null && ! empty($outcome['ok'])) {
                        $ok++;
                    } else {
                        $failed++;
                    }
                }
                if (! empty($results['last_error'])) {
                    $notes[] = (string) $results['last_error'];
                }
                if ($kind === 'stock') {
                    $store->forceFill(['last_stock_push_at' => now()])->save();
                }
            } finally {
                app()->forgetInstance('lazada.route-store');
            }
        }

        return ['ok' => $ok, 'failed' => $failed, 'note' => implode('; ', array_slice(array_unique($notes), 0, 2))];
    }
}
