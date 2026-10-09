<?php

namespace App\Support\Catalog;

use Illuminate\Support\Facades\DB;

final class SkuFallback
{
    public static function fill(int $productId): array
    {
        $minted = ['parent' => null, 'variations' => 0];

        $product = DB::table('products')->where('product_id', $productId)->first(['product_id', 'sku']);
        if (! $product) {
            return $minted;
        }

        $parent = trim((string) ($product->sku ?? ''));
        if ($parent === '') {
            $parent = (string) $productId;
            DB::table('products')->where('product_id', $productId)->update(['sku' => $parent]);
            $minted['parent'] = $parent;
        }

        $n = 0;
        foreach (
            DB::table('product_variations')->where('product_id', $productId)
                ->orderBy('sort_order')->orderBy('id')->get(['id', 'sku']) as $combo
        ) {
            $n++;
            if (trim((string) ($combo->sku ?? '')) !== '') {
                continue;
            }
            DB::table('product_variations')->where('id', $combo->id)
                ->update(['sku' => $parent . '-' . $n, 'updated_at' => now()]);
            $minted['variations']++;
        }

        $n = 0;
        foreach (
            DB::table('product_variation_values')->where('product_id', $productId)
                ->orderBy('product_variation_value_id')->get(['product_variation_value_id', 'sku']) as $value
        ) {
            $n++;
            if (trim((string) ($value->sku ?? '')) !== '') {
                continue;
            }
            DB::table('product_variation_values')->where('product_variation_value_id', $value->product_variation_value_id)
                ->update(['sku' => $parent . '-' . $n]);
            $minted['variations']++;
        }

        return $minted;
    }

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
