<?php

namespace Extensions\tiktok\Services\TikTok;

use Extensions\tiktok\Commands\TikTokPushPrice;
use Extensions\tiktok\Models\TikTokProductGroup;
use Extensions\tiktok\Models\TikTokProductGroupProduct;
use Extensions\tiktok\Models\TikTokSetting;

final class TikTokPushSteps
{
    public static function units(): array
    {
        $units = [];
        foreach (TikTokSetting::enabledStores() as $store) {
            app()->instance('tiktok.route-store', $store);
            try {
                $pids = TikTokProductGroupProduct::query()
                    ->whereIn('tiktok_product_group_id', TikTokProductGroup::query()->select('id'))
                    ->whereNotNull('tiktok_product_id')->where('tiktok_product_id', '!=', '')
                    ->orderBy('product_id')->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
            } finally {
                app()->forgetInstance('tiktok.route-store');
            }
            foreach ($pids as $pid) {
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
            $raw = TikTokSetting::query()->find($storeId);
            if (! $raw) {
                $failed += count($pids);
                continue;
            }
            app()->instance('tiktok.route-store', $raw);
            try {
                $s = $raw->decrypted();
                $sandbox = $raw->mode === 'sandbox';
                $c = [
                    'app_key' => $sandbox ? ($s->sandbox_app_key ?? '') : ($s->app_key ?? ''),
                    'app_secret' => $sandbox ? ($s->sandbox_app_secret ?? '') : ($s->app_secret ?? ''),
                    'token' => $sandbox ? ($s->sandbox_access_token ?? '') : ($s->access_token ?? ''),
                    'shop_cipher' => $sandbox ? ($raw->sandbox_shop_cipher ?? '') : ($raw->shop_cipher ?? ''),
                    'warehouse_id' => $raw->warehouse_id ?: null,
                ];
                if (! $c['app_key'] || ! $c['app_secret'] || ! $c['token']) {
                    return ['error' => 'Store ' . ($raw->store_name ?: '#' . $raw->id) . ' is not connected to TikTok Shop.'];
                }
                $pivots = TikTokProductGroupProduct::query()
                    ->whereIn('tiktok_product_group_id', TikTokProductGroup::query()->select('id'))
                    ->whereIn('product_id', $pids)->whereNotNull('tiktok_product_id')->where('tiktok_product_id', '!=', '')->get();
                $results = app(TikTokStockPricePush::class)->push($kind, $c, $pivots, $pfx, $kind === 'price' ? TikTokPushPrice::priceRule($pivots) : null);
                TikTokStockPricePush::recordOutcomes($kind, $results['outcomes'], $raw);
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
                    $raw->forceFill(['last_stock_push_at' => now()])->save();
                }
            } finally {
                app()->forgetInstance('tiktok.route-store');
            }
        }

        return ['ok' => $ok, 'failed' => $failed, 'note' => implode('; ', array_slice(array_unique($notes), 0, 2))];
    }
}
