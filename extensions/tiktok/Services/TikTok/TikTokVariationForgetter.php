<?php

namespace Extensions\tiktok\Services\TikTok;

use Extensions\tiktok\Models\TikTokListing;
use Extensions\tiktok\Models\TikTokProductGroupProduct;

/**
 * A deleted catalog variation leaves the TikTok SKU map on the listing row
 * and every group pivot (the delete rule one level down, 2026-09-07). The
 * SKU the seller still has on TikTok Shop stays there; the next push names
 * it. Inside VentaSync only.
 */
final class TikTokVariationForgetter
{
    /** @param list<string> $skus */
    public static function forget(int $productId, array $skus): void
    {
        $lower = array_values(array_unique(array_map(fn ($s) => strtolower(trim((string) $s)), $skus)));
        if ($lower === []) {
            return;
        }
        foreach (TikTokListing::query()->where('product_id', $productId)->get() as $row) {
            self::prune($row, $lower);
        }
        foreach (TikTokProductGroupProduct::query()->where('product_id', $productId)->get() as $row) {
            self::prune($row, $lower);
        }
    }

    private static function prune(object $row, array $lower): void
    {
        $map = json_decode((string) ($row->tiktok_sku_id ?? ''), true);
        if (! is_array($map)) {
            return; // a bare id or nothing: no SKU keys to forget
        }
        $kept = [];
        foreach ($map as $sku => $id) {
            if (! in_array(strtolower(trim((string) $sku)), $lower, true)) {
                $kept[$sku] = $id;
            }
        }
        if (count($kept) !== count($map)) {
            $row->forceFill(['tiktok_sku_id' => $kept === [] ? null : json_encode($kept)])->save();
        }
    }
}
