<?php

namespace Extensions\OpenCart\Services\OpenCart;

use App\Models\ScheduledJob;
use Extensions\OpenCart\Models\OpenCartListingLink;
use Extensions\OpenCart\Models\OpenCartStore;

final class OpenCartPushSteps
{
    public static function units(ScheduledJob $job): array
    {
        $stores = OpenCartStore::query()->where('enabled', true);
        if ($job->getOption('store')) {
            $stores->where('id', (int) $job->getOption('store'));
        }
        $units = [];
        foreach ($stores->orderBy('id')->get() as $store) {
            $pids = OpenCartListingLink::query()->where('store_id', $store->id)
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
            $store = OpenCartStore::query()->where('enabled', true)->find($storeId);
            if (! $store) {
                $failed += count($pids);
                continue;
            }
            $sync = new OpenCartProductSync(new OpenCartClient($store), $store);
            $log = $kind === 'price' ? $sync->pushPrices($pids) : $sync->pushQuantities($pids);
            if ($log->status === 'failed') {
                return ['error' => (string) ($log->error_message ?: 'OpenCart did not answer.')];
            }
            $ok += (int) $log->records_updated;
            $failed += (int) $log->records_failed + max(0, count($pids) - (int) $log->records_updated - (int) $log->records_failed);
            foreach (array_slice((array) ($log->details ?? []), 0, 2) as $d) {
                $notes[] = (string) ($d['error'] ?? '');
            }
        }

        return ['ok' => $ok, 'failed' => $failed, 'note' => implode('; ', array_filter(array_unique($notes)))];
    }
}
