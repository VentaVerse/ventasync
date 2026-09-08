<?php

namespace App\Support\Channels;

use Illuminate\Database\Eloquent\Builder;

/**
 * WHICH LISTINGS ARE ON SALE RIGHT NOW.
 *
 * The listing tables all hold the same discount rule - a percent off and a
 * window - so the question "is this row's price cut today?" has one answer
 * on every channel, and this is it (the owner, 2026-09-09: "In the store
 * listing page, it will slash when discount is already on going correct??").
 *
 * ONGOING means two things, not one: the saved window covers this moment,
 * AND the channel has actually been told. A rule saved here and never
 * pushed cuts nothing for a buyer, so a row that shows it struck would be
 * telling the operator something untrue about their own storefront. The
 * column that proves the telling differs per channel - Shopee keeps a
 * promotion id, TikTok an activity id, Lazada a pushed-at stamp - so the
 * caller names it.
 *
 * A missing start or end is an open end, not a closed one: WooCommerce
 * takes a sale with no window at all and applies it until it is removed.
 */
class RunningSale
{
    /**
     * @param  Builder  $listings  the channel's listing query, unfiltered
     * @param  array<int, int>  $productIds  the ids on the page
     * @param  string|null  $pushedColumn  the column that proves the channel was told
     * @return array<int, float>  product_id => percent off
     */
    public static function map(Builder $listings, array $productIds, ?string $pushedColumn = null): array
    {
        $productIds = array_values(array_filter(array_map('intval', $productIds)));
        if ($productIds === []) {
            return [];
        }

        $now = now();

        $query = $listings->whereIn('product_id', $productIds)
            ->whereNotNull('sale_percent')
            ->where(fn ($q) => $q->whereNull('sale_starts_at')->orWhere('sale_starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('sale_ends_at')->orWhere('sale_ends_at', '>=', $now));

        if ($pushedColumn !== null) {
            $query->whereNotNull($pushedColumn);
        }

        return $query->pluck('sale_percent', 'product_id')
            ->map(fn ($v) => (float) $v)
            ->all();
    }
}
