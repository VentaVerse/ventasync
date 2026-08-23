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
     *         quantity, absolute_price, option_image, status.
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
                ->get(['cv.combination_id', 'ovd.name as value_name', 'od.name as option_name']);

            foreach ($pivots as $pv) {
                $pivotData[(int) $pv->combination_id][] = [
                    'value_name' => html_entity_decode((string) $pv->value_name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'option_name' => html_entity_decode((string) $pv->option_name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                ];
            }
        }

        foreach ($comboRows as $c) {
            $entries = $pivotData[(int) $c->id] ?? [];
            $c->option_value_name = implode(' + ', array_map(fn ($e) => $e['value_name'], $entries));
            $c->option_name = implode(' / ', array_unique(array_map(fn ($e) => $e['option_name'], $entries)));
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
                    'pov.product_id', 'pov.sku', 'pov.quantity', 'pov.absolute_price',
                    'od.name as raw_option_name', 'ovd.name as raw_value_name',
                ]);

            foreach ($povRows as $r) {
                $r->option_value_name = html_entity_decode((string) $r->raw_value_name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $r->option_name = html_entity_decode((string) $r->raw_option_name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
}
