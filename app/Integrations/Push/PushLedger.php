<?php

namespace App\Integrations\Push;

use Illuminate\Support\Facades\DB;

final class PushLedger
{
    public const NO_REASON = 'The last sync failed. No reason was recorded.';

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

    public static function mismatchMessage(array $entries, string $channelName): string
    {
        $rows = collect($entries)->unique(fn ($e) => strtolower($e['sku']));
        $names = $rows->map(fn ($e) => ($e['sku'] !== '' ? $e['sku'] : 'a variation') . ' (' . $e['reason'] . ')')->implode(', ');
        $fixes = [];
        if ($rows->contains(fn ($e) => str_starts_with((string) $e['reason'], 'not on'))) {
            $fixes[] = 'Not on ' . $channelName . ': add it with Push update, or delete it from the catalog.';
        }
        if ($rows->contains(fn ($e) => str_starts_with((string) $e['reason'], 'on '))) {
            $fixes[] = 'On ' . $channelName . ' but not in the catalogue: delete it on ' . $channelName . ', then Refresh from ' . $channelName . '.';
        }

        return 'Variations do not match ' . $channelName . ': ' . $names . '. Nothing was pushed. ' . implode(' ', $fixes);
    }

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

    public static function batchTone(int $ok, int $err): string
    {
        if ($err === 0) {
            return 'status';
        }

        return $ok > 0 ? 'warning' : 'error';
    }
}
