<?php

namespace Extensions\TikTok\Services\TikTok;

use Extensions\TikTok\Models\TikTokListing;
use Extensions\TikTok\Models\TikTokProductGroup;
use Extensions\TikTok\Models\TikTokProductGroupProduct;
use Illuminate\Support\Collection;

final class TikTokLinkedRows
{
    public static function get(?array $productIds = null): Collection
    {
        $listings = TikTokListing::query()
            ->whereNotNull('tiktok_product_id')->where('tiktok_product_id', '!=', '')
            ->when($productIds !== null, fn ($q) => $q->whereIn('product_id', $productIds ?: [0]))
            ->orderBy('product_id')->get();
        $held = $listings->pluck('product_id')->map(fn ($v) => (int) $v)->flip();

        $pivots = TikTokProductGroupProduct::query()
            ->whereIn('tiktok_product_group_id', TikTokProductGroup::query()->select('id'))
            ->whereNotNull('tiktok_product_id')->where('tiktok_product_id', '!=', '')
            ->when($productIds !== null, fn ($q) => $q->whereIn('product_id', $productIds ?: [0]))
            ->orderBy('product_id')->get()
            ->reject(fn ($p) => $held->has((int) $p->product_id))
            ->unique('product_id');

        return $listings->concat($pivots)->values();
    }
}
