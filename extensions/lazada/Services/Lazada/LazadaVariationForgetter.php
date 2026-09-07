<?php

namespace Extensions\lazada\Services\Lazada;

use Extensions\lazada\Models\LazadaProduct;
use Extensions\lazada\Models\LazadaProductVariant;

/**
 * A deleted catalog variation forgets its Lazada variant row on every store
 * (the delete rule one level down, 2026-09-07). The SKU the seller still
 * has on Lazada stays there; the next push names it. Inside VentaSync only.
 */
final class LazadaVariationForgetter
{
    /** @param list<string> $skus */
    public static function forget(int $productId, array $skus): void
    {
        $lower = array_values(array_unique(array_map(fn ($s) => strtolower(trim((string) $s)), $skus)));
        if ($lower === []) {
            return;
        }
        $listingIds = LazadaProduct::query()->where('product_id', $productId)->pluck('id')->all();
        if ($listingIds === []) {
            return;
        }
        foreach (LazadaProductVariant::query()->whereIn('lazada_product_id', $listingIds)->get() as $variant) {
            if (in_array(strtolower(trim((string) $variant->seller_sku)), $lower, true)) {
                $variant->delete();
            }
        }
    }
}
