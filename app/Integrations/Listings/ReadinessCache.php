<?php

namespace App\Integrations\Listings;

use Illuminate\Support\Facades\DB;

/**
 * The materialised readiness store. A channel asks for the ready/gaps of a set
 * of products; this reads the stored answer and recomputes only the rows whose
 * inputs changed since they were last computed (a per-product `stamp`, e.g. the
 * catalogue product's date_modified plus the listing's and group's updated_at).
 *
 * So a warm page load is two queries (the stored rows and the current stamps)
 * plus nothing else when nothing changed — O(1) in catalogue size — while a
 * product that was just edited is recomputed on the next load, so the answer is
 * never stale. It is a cache: truncating it changes no behaviour, only speed.
 *
 * The compute callback is the channel's OWN readiness engine, called in one
 * batched pass for the stale products; this class never judges readiness itself.
 */
final class ReadinessCache
{
    public const TABLE = 'listing_readiness';

    /**
     * @param  list<int>  $productIds  the store's scope
     * @param  callable(list<int>): array<int, string>  $stamps  current stamp per product id
     * @param  callable(list<int>): array<int, array{ready: bool, gaps: array}>  $compute  fresh readiness for the given (stale) product ids
     * @return array<int, array{ready: bool, gaps: array}>  keyed by product id
     */
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

        // Which rows are missing or out of date against the current inputs.
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

    /** Drop cached rows so they recompute next read — for a targeted invalidation the stamp cannot see (e.g. a category refetch). */
    public static function forget(string $channel, int $storeId, array $productIds = []): void
    {
        $q = DB::table(self::TABLE)->where('channel', $channel)->where('store_id', $storeId);
        if ($productIds !== []) {
            $q->whereIn('product_id', array_values(array_unique(array_map('intval', $productIds))));
        }
        $q->delete();
    }
}
