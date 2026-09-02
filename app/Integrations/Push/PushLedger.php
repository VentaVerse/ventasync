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
     * The honest suffix for a flash message: what was healed and what
     * was skipped, by name.
     *
     * @param array{healed_items?: array, healed_products?: array, healed_listings?: array, skipped_variations?: list<array{product_id: int, sku: string, reason: string}>} $results
     */
    public static function clause(array $results, string $channelName): string
    {
        $parts = [];
        $healed = $results['healed_items'] ?? $results['healed_products'] ?? $results['healed_listings'] ?? [];
        if (!empty($healed)) {
            $n = count(array_unique($healed));
            $parts[] = $n . ' ' . ($n === 1 ? 'item' : 'items') . ' re-read from ' . $channelName . ' and repaired mid-push';
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
        foreach (DB::table($pfx . 'product_option_value')
            ->whereIn('product_id', $productIds ?: [0])
            ->whereNotNull('sku')->where('sku', '!=', '')
            ->get(['product_id', 'sku']) as $r) {
            $skus[(int) $r->product_id][trim((string) $r->sku)] = true;
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
