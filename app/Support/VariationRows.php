<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The variation rows a product list shows under each parent, built ONCE.
 *
 * The core product list reads product_option_combinations - the sellable
 * unit, which is what carries the sku, the stock, the price and (since
 * 2026-08-21) the variation's own image. The six marketplace pages that
 * also show variations each carried their own query against
 * product_option_value instead, which has no image and, on a two-axis
 * product, lists per-VALUE rows that are not what is actually sold.
 * the owner's ask (2026-08-24): the marketplace lists show variations the way
 * the core list does, images and SKUs included. One query serves all of
 * them, so the two presentations can never drift apart again.
 */
class VariationRows
{
    /**
     * @param array<int> $productIds
     * @return Collection<int, Collection<int, object>> keyed by product_id;
     *         each row carries option_value_name, option_name, sku,
     *         quantity, absolute_price, option_image, status, and
     *         option_value_ids (one product_option_value_id per axis).
     */
    public static function forProducts(array $productIds): Collection
    {
        if (empty($productIds)) {
            return collect();
        }

        $p = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $comboRows = DB::table('product_option_combinations as c')
            ->whereIn('c.product_id', $productIds)
            ->orderBy('c.product_id')
            ->orderBy('c.sort_order')
            ->get(['c.id', 'c.product_id', 'c.sku', 'c.quantity', 'c.absolute_price', 'c.image', 'c.status']);

        // Pivot: combination -> its option value names and option group names.
        $comboIds = $comboRows->pluck('id')->all();
        $pivotData = [];
        if (!empty($comboIds)) {
            $pivots = DB::table('product_option_combination_values as cv')
                ->join($p . 'product_option_value as pov', 'cv.product_option_value_id', '=', 'pov.product_option_value_id')
                ->join($p . 'option_value_description as ovd', function ($j) use ($langId) {
                    $j->on('pov.option_value_id', '=', 'ovd.option_value_id')
                      ->where('ovd.language_id', '=', $langId);
                })
                ->join($p . 'option_description as od', function ($j) use ($langId) {
                    $j->on('pov.option_id', '=', 'od.option_id')
                      ->where('od.language_id', '=', $langId);
                })
                ->join($p . 'product_option as po', 'pov.product_option_id', '=', 'po.product_option_id')
                ->whereIn('cv.combination_id', $comboIds)
                ->orderBy('po.product_option_id')
                ->get(['cv.combination_id', 'cv.product_option_value_id', 'ovd.name as value_name', 'od.name as option_name']);

            foreach ($pivots as $pv) {
                $pivotData[(int) $pv->combination_id][] = [
                    'value_name' => html_entity_decode((string) $pv->value_name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'option_name' => html_entity_decode((string) $pv->option_name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'option_value_id' => (int) $pv->product_option_value_id,
                ];
            }
        }

        foreach ($comboRows as $c) {
            $entries = $pivotData[(int) $c->id] ?? [];
            $c->option_value_name = implode(' + ', array_map(fn ($e) => $e['value_name'], $entries));
            $c->option_name = implode(' / ', array_unique(array_map(fn ($e) => $e['option_name'], $entries)));
            /* The option value ids behind the combination, one per axis,
             * for a caller that needs to cost it (LineCost) or match an
             * order line to it. */
            $c->option_value_ids = array_map(fn ($e) => $e['option_value_id'], $entries);
            /* The variation's OWN picture, or nothing. Not the parent's as
             * a fallback: the parent sits directly above in every list that
             * renders these, and repeating its photograph down the
             * variations defeats the column's job of telling them apart. */
            $c->option_image = trim((string) ($c->image ?? '')) ?: null;
        }

        $rows = $comboRows->groupBy(fn ($c) => (int) $c->product_id);

        /* Fallback for products whose variations predate combinations and
         * were never re-saved (4 of 68 on the live data at the time of
         * writing): their sellable figures still live only on
         * product_option_value. No image there - the column arrived with
         * combinations - so these rows show the placeholder. */
        $missing = array_values(array_diff(
            array_map('intval', $productIds),
            $rows->keys()->all()
        ));
        if (!empty($missing)) {
            $povRows = DB::table($p . 'product_option_value as pov')
                ->join($p . 'option_description as od', function ($j) use ($langId) {
                    $j->on('pov.option_id', '=', 'od.option_id')->where('od.language_id', '=', $langId);
                })
                ->join($p . 'option_value_description as ovd', function ($j) use ($langId) {
                    $j->on('pov.option_value_id', '=', 'ovd.option_value_id')->where('ovd.language_id', '=', $langId);
                })
                ->whereIn('pov.product_id', $missing)
                ->orderBy('pov.product_id')
                ->orderBy('pov.product_option_value_id')
                ->get([
                    'pov.product_id', 'pov.product_option_value_id', 'pov.sku', 'pov.quantity', 'pov.absolute_price',
                    'od.name as raw_option_name', 'ovd.name as raw_value_name',
                ]);

            foreach ($povRows as $r) {
                $r->option_value_name = html_entity_decode((string) $r->raw_value_name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $r->option_name = html_entity_decode((string) $r->raw_option_name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $r->option_value_ids = [(int) $r->product_option_value_id];
                $r->option_image = null;
                $r->status = 1;
            }

            /* union, NOT merge: these collections are keyed by integer
             * product_id, and Collection::merge runs through array_merge,
             * which renumbers integer keys - even a merge with an empty
             * collection turns key 1800 into key 0. union preserves keys,
             * and $missing already excludes every existing one. */
            $rows = $rows->union($povRows->groupBy(fn ($r) => (int) $r->product_id));
        }

        return $rows;
    }

    /**
     * The rows every store's pages show: the catalog's variations that are
     * switched on. The catalog is the source of truth, so a variation it
     * switched off appears on no channel and no store. A store's own
     * "Sell here" switch lives on its listing page and changes nothing
     * here. The catalog list and the API keep forProducts(), which shows
     * them all, since that is where a variation is switched back on.
     *
     * Given the page's store, each row also carries sold_here: that store's
     * own switch, drawn as a dot beside the variation. It changes which
     * dot shows, never which rows show.
     *
     * @param array<int> $productIds
     * @return Collection<int, Collection<int, object>> keyed by product_id; a product left with no rows is absent
     */
    public static function forListing(array $productIds, ?string $channel = null, ?int $storeId = null): Collection
    {
        $rows = self::forProducts($productIds)
            ->map(fn (Collection $list) => $list->filter(fn ($r) => (int) ($r->status ?? 1) !== 0)->values())
            ->filter(fn (Collection $list) => $list->isNotEmpty());
        if ($channel === null || $storeId === null || $rows->isEmpty()) {
            return $rows;
        }

        $off = \App\Integrations\Listings\ListingVariations::offOnStore($channel, $storeId, array_map('intval', $productIds));

        return $rows->each(fn (Collection $list, $pid) => $list->each(function ($r) use ($off, $pid) {
            $r->sold_here = \App\Integrations\Listings\ListingVariations::allows($off, (int) $pid, $r->sku ?? null);
        }));
    }
}
