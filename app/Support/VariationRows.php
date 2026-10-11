<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class VariationRows
{
    public static function forProducts(array $productIds): Collection
    {
        if (empty($productIds)) {
            return collect();
        }

        $langId = (int) config('catalog.default_language_id');

        $comboRows = DB::table('product_variations as c')
            ->whereIn('c.product_id', $productIds)
            ->orderBy('c.product_id')
            ->orderBy('c.sort_order')
            ->get(['c.id', 'c.product_id', 'c.sku', 'c.quantity', 'c.absolute_price', 'c.image', 'c.status']);

        $comboIds = $comboRows->pluck('id')->all();
        $pivotData = [];
        if (!empty($comboIds)) {
            $pivots = DB::table('product_variation_parts as cv')
                ->join('product_variation_values as pov', 'cv.product_variation_value_id', '=', 'pov.product_variation_value_id')
                ->join('variation_value_descriptions as ovd', function ($j) use ($langId) {
                    $j->on('pov.variation_value_id', '=', 'ovd.variation_value_id')
                      ->where('ovd.language_id', '=', $langId);
                })
                ->join('variation_type_descriptions as od', function ($j) use ($langId) {
                    $j->on('pov.variation_type_id', '=', 'od.variation_type_id')
                      ->where('od.language_id', '=', $langId);
                })
                ->join('product_variation_types as po', 'pov.product_variation_type_id', '=', 'po.product_variation_type_id')
                ->whereIn('cv.product_variation_id', $comboIds)
                ->orderBy('po.product_variation_type_id')
                ->get(['cv.product_variation_id', 'cv.product_variation_value_id', 'ovd.name as value_name', 'od.name as variation_type_name']);

            foreach ($pivots as $pv) {
                $pivotData[(int) $pv->product_variation_id][] = [
                    'value_name' => html_entity_decode((string) $pv->value_name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'variation_type_name' => html_entity_decode((string) $pv->variation_type_name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'variation_value_id' => (int) $pv->product_variation_value_id,
                ];
            }
        }

        foreach ($comboRows as $c) {
            $entries = $pivotData[(int) $c->id] ?? [];
            $c->variation_value_name = implode(' + ', array_map(fn ($e) => $e['value_name'], $entries));
            $c->variation_type_name = implode(' / ', array_unique(array_map(fn ($e) => $e['variation_type_name'], $entries)));
            $c->variation_value_ids = array_map(fn ($e) => $e['variation_value_id'], $entries);
            $c->option_image = trim((string) ($c->image ?? '')) ?: null;
        }

        $rows = $comboRows->groupBy(fn ($c) => (int) $c->product_id);

        $missing = array_values(array_diff(
            array_map('intval', $productIds),
            $rows->keys()->all()
        ));
        if (!empty($missing)) {
            $povRows = DB::table('product_variation_values as pov')
                ->join('variation_type_descriptions as od', function ($j) use ($langId) {
                    $j->on('pov.variation_type_id', '=', 'od.variation_type_id')->where('od.language_id', '=', $langId);
                })
                ->join('variation_value_descriptions as ovd', function ($j) use ($langId) {
                    $j->on('pov.variation_value_id', '=', 'ovd.variation_value_id')->where('ovd.language_id', '=', $langId);
                })
                ->whereIn('pov.product_id', $missing)
                ->orderBy('pov.product_id')
                ->orderBy('pov.product_variation_value_id')
                ->get([
                    'pov.product_id', 'pov.product_variation_value_id', 'pov.sku', 'pov.quantity', 'pov.absolute_price',
                    'od.name as raw_variation_type_name', 'ovd.name as raw_value_name',
                ]);

            foreach ($povRows as $r) {
                $r->variation_value_name = html_entity_decode((string) $r->raw_value_name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $r->variation_type_name = html_entity_decode((string) $r->raw_variation_type_name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $r->variation_value_ids = [(int) $r->product_variation_value_id];
                $r->option_image = null;
                $r->status = 1;
            }

            $rows = $rows->union($povRows->groupBy(fn ($r) => (int) $r->product_id));
        }

        return $rows;
    }

    public static function forListing(array $productIds, ?string $channel = null, ?int $storeId = null): Collection
    {
        $rows = self::forProducts($productIds)
            ->map(fn (Collection $list) => $list->filter(fn ($r) => (int) ($r->status ?? 1) !== 0)->values())
            ->filter(fn (Collection $list) => $list->isNotEmpty());
        if ($channel === null || $storeId === null || $rows->isEmpty()) {
            return $rows;
        }

        $ids = array_map('intval', $productIds);
        $off = \App\Channels\Listings\ListingVariations::offOnStore($channel, $storeId, $ids);
        $held = DB::table(\App\Channels\Listings\ListingVariations::STORE_SKUS)
            ->where('channel', $channel)->where('store_id', $storeId)->whereIn('product_id', $ids)
            ->pluck('skus', 'product_id');

        return $rows->each(fn (Collection $list, $pid) => $list->each(function ($r) use ($off, $held, $pid) {
            $sku = strtolower(trim((string) ($r->sku ?? '')));
            $r->sold_here = \App\Channels\Listings\ListingVariations::allows($off, (int) $pid, $r->sku ?? null);
            $r->missing_here = false;
            if ($r->sold_here && $sku !== '' && $held->has($pid)) {
                $have = array_map(fn ($s) => strtolower(trim((string) $s)), (array) json_decode((string) $held->get($pid), true));
                if (! in_array($sku, $have, true)) {
                    $r->sold_here = false;
                    $r->missing_here = true;
                }
            }
        }));
    }
}
