<?php

namespace Extensions\Lazada\Services\Lazada;

use Extensions\Lazada\Models\LazadaListing;
use Extensions\Lazada\Models\LazadaListingVariation;

final class LazadaVariationForgetter
{
    public static function forget(int $productId, array $skus): void
    {
        $lower = array_values(array_unique(array_map(fn ($s) => strtolower(trim((string) $s)), $skus)));
        if ($lower === []) {
            return;
        }
        $listingIds = LazadaListing::query()->where('product_id', $productId)->pluck('id')->all();
        if ($listingIds === []) {
            return;
        }
        foreach (LazadaListingVariation::query()->whereIn('lazada_listing_id', $listingIds)->get() as $variant) {
            if (in_array(strtolower(trim((string) $variant->seller_sku)), $lower, true)) {
                $variant->delete();
            }
        }
    }
}
