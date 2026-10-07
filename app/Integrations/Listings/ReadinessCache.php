<?php

namespace App\Integrations\Listings;

use Illuminate\Support\Facades\DB;

final class ReadinessCache
{
    public const TABLE = 'listing_readiness';

    public static function resolve(string $channel, int $storeId, array $productIds, callable $stamps, callable $compute): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === []) {
            return [];
        }

        $current = $stamps($productIds);

        $stored = [];
        foreach (array_chunk($productIds, 1000) as $chunk) {
            foreach (DB::table(self::TABLE)
                ->where('channel', $channel)->where('store_id', $storeId)
                ->whereIn('product_id', $chunk)
                ->get(['product_id', 'ready', 'gaps', 'stamp']) as $row) {
                $stored[(int) $row->product_id] = [
                    'ready' => (bool) $row->ready,
                    'gaps' => json_decode((string) $row->gaps, true) ?: [],
                    'stamp' => (string) $row->stamp,
                ];
            }
        }

        $stale = [];
        foreach ($productIds as $pid) {
            $want = (string) ($current[$pid] ?? '');
            if (! isset($stored[$pid]) || $stored[$pid]['stamp'] !== $want) {
                $stale[] = $pid;
            }
        }

        if ($stale !== []) {
            $fresh = $compute($stale);
            $now = now();
            $rows = [];
            foreach ($stale as $pid) {
                $answer = $fresh[$pid] ?? ['ready' => false, 'gaps' => []];
                $ready = (bool) ($answer['ready'] ?? false);
                $gaps = (array) ($answer['gaps'] ?? []);
                $rows[] = [
                    'channel' => $channel,
                    'store_id' => $storeId,
                    'product_id' => $pid,
                    'ready' => $ready,
                    'gaps' => json_encode($gaps),
                    'stamp' => (string) ($current[$pid] ?? ''),
                    'computed_at' => $now,
                ];
                $stored[$pid] = ['ready' => $ready, 'gaps' => $gaps, 'stamp' => (string) ($current[$pid] ?? '')];
            }
            foreach (array_chunk($rows, 500) as $batch) {
                DB::table(self::TABLE)->upsert($batch, ['channel', 'store_id', 'product_id'], ['ready', 'gaps', 'stamp', 'computed_at']);
            }
        }

        $out = [];
        foreach ($productIds as $pid) {
            $out[$pid] = ['ready' => (bool) ($stored[$pid]['ready'] ?? false), 'gaps' => (array) ($stored[$pid]['gaps'] ?? [])];
        }

        return $out;
    }

    public static function forget(string $channel, int $storeId, array $productIds = []): void
    {
        $q = DB::table(self::TABLE)->where('channel', $channel)->where('store_id', $storeId);
        if ($productIds !== []) {
            $q->whereIn('product_id', array_values(array_unique(array_map('intval', $productIds))));
        }
        $q->delete();
    }
}
