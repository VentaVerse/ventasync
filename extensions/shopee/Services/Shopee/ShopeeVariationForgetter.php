<?php

namespace Extensions\shopee\Services\Shopee;

use Extensions\shopee\Models\ShopeeProductLink;

/**
 * A deleted catalog variation forgets its Shopee model link on every store
 * (the delete rule one level down, 2026-09-07). The model the seller still
 * has on Shopee stays there; the next push names it. Inside VentaSync only.
 */
final class ShopeeVariationForgetter
{
    /** @param list<string> $skus */
    public static function forget(int $productId, array $skus): void
    {
        $lower = array_values(array_unique(array_map(fn ($s) => strtolower(trim((string) $s)), $skus)));
        if ($lower === []) {
            return;
        }
        // No store bound outside a store page, so the scope sees every store.
        foreach (ShopeeProductLink::query()->where('product_id', $productId)->whereNotNull('shopee_model_id')->get() as $link) {
            if (in_array(strtolower(trim((string) $link->sku)), $lower, true)) {
                $link->delete();
            }
        }
    }
}
