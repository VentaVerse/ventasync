<?php

namespace App\Support\Catalog;

use Illuminate\Support\Facades\DB;

/**
 * A PRODUCT THIS APPLICATION HOLDS ALWAYS HAS A CODE.
 *
 * Creating a product by hand requires a SKU, unique, validated. Creating
 * one by IMPORT did not: every import wrote whatever code the store had,
 * and stores are free to have none - Lazada's importer wrote an empty SKU
 * for any product with variations, whatever the source carried.
 *
 * That is not a cosmetic gap. The SKU is the identity every channel keys
 * on: it is what a push sends, what a link check searches by, and the only
 * way a listing can be matched back to its product when a link is lost. A
 * product with no code cannot be found again, so the next push builds a
 * second listing beside the first - which is exactly what happened to
 * the owner on 2026-09-09, twice.
 *
 * So when the store gives no code, one is minted from the product's own
 * id, and it is minted ONCE: this only ever fills a blank, never rewrites
 * a code that exists. A code minted here is pushed like any other, so from
 * the next push onward the marketplace carries it too.
 *
 * THE CAVEAT, ACCEPTED BY OWNER 2026-09-09: a listing already on a store,
 * created there without a SKU before any of this, still carries none, so a
 * minted code cannot match it. Keeping one code across marketplaces is the
 * seller's own job, not something this application can do for them after
 * the fact. What it can do is never be the reason a code is missing.
 */
final class SkuFallback
{
    /**
     * Fill in what the store did not give. Call it after an import has
     * written the product AND its variations.
     *
     * @return array{parent: ?string, variations: int} what was minted, so
     *         an import can say so rather than change the catalog silently
     */
    public static function fill(int $productId): array
    {
        $pfx = (string) config('catalog.prefix');
        $minted = ['parent' => null, 'variations' => 0];

        $product = DB::table($pfx . 'product')->where('product_id', $productId)->first(['product_id', 'sku']);
        if (! $product) {
            return $minted;
        }

        $parent = trim((string) ($product->sku ?? ''));
        if ($parent === '') {
            // The product's own id: short, unique by construction, and
            // already the number every page shows beside it.
            $parent = (string) $productId;
            DB::table($pfx . 'product')->where('product_id', $productId)->update(['sku' => $parent]);
            $minted['parent'] = $parent;
        }

        /* Variations are numbered off the parent in their own order, and
         * only where they are blank, so a second import cannot renumber a
         * code the marketplace has already been told. */
        $n = 0;
        foreach (
            DB::table('product_option_combinations')->where('product_id', $productId)
                ->orderBy('sort_order')->orderBy('id')->get(['id', 'sku']) as $combo
        ) {
            $n++;
            if (trim((string) ($combo->sku ?? '')) !== '') {
                continue;
            }
            DB::table('product_option_combinations')->where('id', $combo->id)
                ->update(['sku' => $parent . '-' . $n, 'updated_at' => now()]);
            $minted['variations']++;
        }

        $n = 0;
        foreach (
            DB::table($pfx . 'product_option_value')->where('product_id', $productId)
                ->orderBy('product_option_value_id')->get(['product_option_value_id', 'sku']) as $value
        ) {
            $n++;
            if (trim((string) ($value->sku ?? '')) !== '') {
                continue;
            }
            DB::table($pfx . 'product_option_value')->where('product_option_value_id', $value->product_option_value_id)
                ->update(['sku' => $parent . '-' . $n]);
            $minted['variations']++;
        }

        return $minted;
    }

    /** The sentence an import adds when it had to mint a code. */
    public static function note(array $minted): string
    {
        if ($minted['parent'] === null && $minted['variations'] < 1) {
            return '';
        }

        $parts = [];
        if ($minted['parent'] !== null) {
            $parts[] = 'SKU ' . $minted['parent'];
        }
        if ($minted['variations'] > 0) {
            $parts[] = $minted['variations'] . ' variation ' . ($minted['variations'] === 1 ? 'SKU' : 'SKUs');
        }

        return 'The store gave no product code, so ' . implode(' and ', $parts)
            . ' were created here. They go up with the next push.';
    }
}
