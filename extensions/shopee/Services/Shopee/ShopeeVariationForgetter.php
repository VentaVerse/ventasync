<?php

namespace Extensions\shopee\Services\Shopee;

use Extensions\shopee\Models\ShopeeProductLink;

final class ShopeeVariationForgetter
{
    public static function forget(int $productId, array $skus): void
    {
        $lower = array_values(array_unique(array_map(fn ($s) => strtolower(trim((string) $s)), $skus)));
        if ($lower === []) {
            return;
        }
        foreach (ShopeeProductLink::query()->where('product_id', $productId)->whereNotNull('shopee_model_id')->get() as $link) {
            if (in_array(strtolower(trim((string) $link->sku)), $lower, true)) {
                $link->delete();
            }
        }
    }
}
