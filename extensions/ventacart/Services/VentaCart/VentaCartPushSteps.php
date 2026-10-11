<?php

namespace Extensions\VentaCart\Services\VentaCart;

use App\Models\ScheduledJob;
use Extensions\VentaCart\Models\VentaCartListingLink;
use Extensions\VentaCart\Models\VentaCartStore;

final class VentaCartPushSteps
{
    public static function units(ScheduledJob $job): array
    {
        $stores = VentaCartStore::query()->where('enabled', true);
        if ($job->getOption('store')) {
            $stores->where('id', (int) $job->getOption('store'));
        }
        $units = [];
        foreach ($stores->orderBy('id')->get() as $store) {
            $pids = VentaCartListingLink::query()->where('store_id', $store->id)->whereNotNull('ventacart_product_id')
                ->orderBy('product_id')->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
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
            $store = VentaCartStore::query()->where('enabled', true)->find($storeId);
            if (! $store) {
                $failed += count($pids);
                continue;
            }
            $r = VentaCartStockPricePush::for($store)->push($pids, $kind === 'stock', $kind === 'price');
            VentaCartStockPricePush::recordOutcomes((int) $store->id, $r['outcomes']);
            $ok += (int) $r['ok'];
            $failed += (int) $r['failed'] + (int) $r['skipped'];
            foreach (array_slice($r['errors'], 0, 2) as $text) {
                $notes[] = (string) $text;
            }
            if ($kind === 'stock') {
                $store->forceFill(['last_stock_push_at' => now()])->save();
            }
        }

        return ['ok' => $ok, 'failed' => $failed, 'note' => implode('; ', array_slice(array_unique($notes), 0, 2))];
    }
}
