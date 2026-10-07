<?php

namespace Extensions\tiktok\Services\TikTok;

use Extensions\tiktok\Models\TikTokListing;
use Extensions\tiktok\Models\TikTokProductGroupProduct;

final class TikTokVariationForgetter
{
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
            return;
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
