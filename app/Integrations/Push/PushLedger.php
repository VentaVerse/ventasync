<?php

namespace App\Integrations\Push;

use Illuminate\Support\Facades\DB;

/**
 * The shared half of every channel's stock/price sender (Phase F, #184).
 * Three senders speak one ledger dialect, so no two channels can phrase
 * "what was skipped and what was healed" apart - the vocabulary lesson
 * of Phase B, applied to flashes.
 */
final class PushLedger
{
    /**
     * What the Error column says when a push or a sync failed and the
     * channel gave no reason. Blank in a column that only ever fills on
     * failure reads as nothing wrong, which is the opposite of the truth.
     */
    public const NO_REASON = 'The last sync failed. No reason was recorded.';

    /**
     * The honest suffix for a flash message: what was healed and what
     * was skipped, by name.
     *
     * @param array{healed_items?: array, healed_products?: array, healed_listings?: array, mismatched?: list<array{product_id: int, sku: string, reason: string}>, skipped_variations?: list<array{product_id: int, sku: string, reason: string}>} $results
     */
    public static function clause(array $results, string $channelName): string
    {
        $parts = [];
        $healed = $results['healed_items'] ?? $results['healed_products'] ?? $results['healed_listings'] ?? [];
        if (!empty($healed)) {
            $n = count(array_unique($healed));
            $parts[] = $n . ' ' . ($n === 1 ? 'item' : 'items') . ' re-read from ' . $channelName . ' and repaired mid-push';
        }
        if (!empty($results['mismatched'])) {
            $rows = collect($results['mismatched'])->unique(fn ($v) => $v['product_id'] . '|' . strtolower($v['sku']));
            $products = $rows->pluck('product_id')->unique()->count();
            $names = $rows->take(5)->map(fn ($v) => $v['sku'] . ' (' . $v['reason'] . ')')->implode(', ');
            $parts[] = $products . ' product' . ($products === 1 ? '' : 's') . ' not pushed, the variations do not match ' . $channelName . ': '
                . $names . ($rows->count() > 5 ? ', ...' : '');
        }
        if (!empty($results['skipped_variations'])) {
            $skips = collect($results['skipped_variations'])->unique(fn ($v) => $v['product_id'] . '|' . $v['sku']);
            $names = $skips->take(5)->map(fn ($v) => ($v['sku'] !== '' ? $v['sku'] : '#' . $v['product_id']) . ' (' . $v['reason'] . ')')->implode(', ');
            $parts[] = $skips->count() . ' variation' . ($skips->count() === 1 ? '' : 's') . ' skipped: '
                . $names . ($skips->count() > 5 ? ', ...' : '');
        }

        return $parts ? ' ' . implode('. ', $parts) . '.' : '';
    }

    /**
     * The one sentence a product wears when its variations and the channel's
     * differ (the owner, 2026-09-07): nothing is pushed for it, in either
     * direction of the difference. A partial push used to send what matched
     * and name the rest as skipped; the operator could not tell which product
     * that was, so the product fails whole and says why, and the fix is to
     * make the catalog and the channel hold the same variations.
     *
     * @param list<array{sku:string, reason:string}> $entries
     */
    public static function mismatchMessage(array $entries, string $channelName): string
    {
        $rows = collect($entries)->unique(fn ($e) => strtolower($e['sku']));
        $names = $rows->map(fn ($e) => ($e['sku'] !== '' ? $e['sku'] : 'a variation') . ' (' . $e['reason'] . ')')->implode(', ');
        /* The fix, by direction, naming what exists: Push update can add a
         * missing variation (Shopee refuses it past its price ratio, and
         * says so), and a variation the catalog lacks is removed on the
         * store, then Refresh reads the store again. */
        $fixes = [];
        if ($rows->contains(fn ($e) => str_starts_with((string) $e['reason'], 'not on'))) {
            $fixes[] = 'Not on ' . $channelName . ': add it with Push update, or delete it from the catalog.';
        }
        if ($rows->contains(fn ($e) => str_starts_with((string) $e['reason'], 'on '))) {
            $fixes[] = 'On ' . $channelName . ' but not in the catalogue: delete it on ' . $channelName . ', then Refresh from ' . $channelName . '.';
        }

        return 'Variations do not match ' . $channelName . ': ' . $names . '. Nothing was pushed. ' . implode(' ', $fixes);
    }

    /**
     * Every variation SKU the catalogue holds per product: combinations
     * first, then option values. The skip ledger's ground truth on all
     * three channels.
     *
     * @return array<int, list<string>>
     */
    public static function erpVariationSkus(array $productIds, string $pfx): array
    {
        $skus = [];
        foreach (DB::table('product_option_combinations')
            ->whereIn('product_id', $productIds ?: [0])
            ->whereNotNull('sku')->where('sku', '!=', '')
            ->get(['product_id', 'sku']) as $r) {
            $skus[(int) $r->product_id][trim((string) $r->sku)] = true;
        }
        /* A product sold in combinations sells its combinations: the axis
         * values' own SKUs (the single-axis table) are not variations of it
         * and must not be asked of the channel. Only a product without
         * combinations sells its option values. */
        foreach (DB::table($pfx . 'product_option_value')
            ->whereIn('product_id', $productIds ?: [0])
            ->whereNotNull('sku')->where('sku', '!=', '')
            ->get(['product_id', 'sku']) as $r) {
            if (isset($skus[(int) $r->product_id])) {
                continue;
            }
            $single[(int) $r->product_id][trim((string) $r->sku)] = true;
        }
        foreach ($single ?? [] as $pid => $set) {
            $skus[$pid] = $set;
        }

        return array_map(fn ($set) => array_keys($set), $skus);
    }

    /**
     * The flash key a batch outcome deserves. 'status' renders a green
     * tick, and "0 success, 12 error" must never wear one.
     */
    public static function batchTone(int $ok, int $err): string
    {
        if ($err === 0) {
            return 'status';
        }

        return $ok > 0 ? 'warning' : 'error';
    }
}
