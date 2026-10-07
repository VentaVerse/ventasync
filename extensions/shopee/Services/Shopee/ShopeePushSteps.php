<?php

namespace Extensions\shopee\Services\Shopee;

use Extensions\shopee\Commands\ShopeePushPrice;
use Extensions\shopee\Models\ShopeeProductLink;
use Extensions\shopee\Models\ShopeeSetting;
use Illuminate\Support\Facades\DB;

final class ShopeePushSteps
{
    public static function units(): array
    {
        $pfx = (string) config('catalog.prefix');
        $units = [];
        foreach (ShopeeSetting::query()->where('enabled', true)->orderBy('id')->get() as $store) {
            $pids = ShopeeProductLink::forStore($store)->whereNotNull('shopee_item_id')->distinct()->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
            if ($pids === []) {
                continue;
            }
            $enabled = DB::table($pfx . 'product')->whereIn('product_id', $pids)->where('status', 1)->pluck('product_id')->map(fn ($v) => (int) $v)->all();
            foreach ($enabled as $pid) {
                $units[] = $store->id . ':' . $pid;
            }
        }

        return $units;
    }

    public static function step(string $kind, array $units): array
    {
        $pfx = (string) config('catalog.prefix');
        $byStore = [];
        foreach ($units as $u) {
            [$sid, $pid] = array_pad(explode(':', (string) $u, 2), 2, 0);
            $byStore[(int) $sid][] = (int) $pid;
        }
        $ok = 0;
        $failed = 0;
        $notes = [];
        foreach ($byStore as $storeId => $pids) {
            $store = ShopeeSetting::query()->find($storeId);
            if (! $store) {
                $failed += count($pids);
                continue;
            }
            app()->instance('shopee.route-store', $store);
            try {
                $setting = $store->decrypted();
                $auth = ShopeeSetting::activeAuth($setting);
                if (! $auth['complete']) {
                    return ['error' => 'Store ' . ($store->store_name ?: '#' . $store->id) . ' is not connected to Shopee.'];
                }
                $links = ShopeeProductLink::forStore($store)->whereIn('product_id', $pids)->get();
                $results = app(ShopeeStockPricePush::class)->push($kind, $auth, $links, $pfx, $kind === 'price' ? ShopeePushPrice::priceRule($pids) : null);
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
                app()->forgetInstance('shopee.route-store');
            }
        }

        return ['ok' => $ok, 'failed' => $failed, 'note' => implode('; ', array_slice(array_unique($notes), 0, 2))];
    }
}
