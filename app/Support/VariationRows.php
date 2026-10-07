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

        $p = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $comboRows = DB::table('product_option_combinations as c')
            ->whereIn('c.product_id', $productIds)
            ->orderBy('c.product_id')
            ->orderBy('c.sort_order')
            ->get(['c.id', 'c.product_id', 'c.sku', 'c.quantity', 'c.absolute_price', 'c.image', 'c.status']);

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
            $c->option_value_ids = array_map(fn ($e) => $e['option_value_id'], $entries);
            $c->option_image = trim((string) ($c->image ?? '')) ?: null;
        }

        $rows = $comboRows->groupBy(fn ($c) => (int) $c->product_id);

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
        $off = \App\Integrations\Listings\ListingVariations::offOnStore($channel, $storeId, $ids);
        $held = DB::table(\App\Integrations\Listings\ListingVariations::STORE_SKUS)
            ->where('channel', $channel)->where('store_id', $storeId)->whereIn('product_id', $ids)
            ->pluck('skus', 'product_id');

        return $rows->each(fn (Collection $list, $pid) => $list->each(function ($r) use ($off, $held, $pid) {
            $sku = strtolower(trim((string) ($r->sku ?? '')));
            $r->sold_here = \App\Integrations\Listings\ListingVariations::allows($off, (int) $pid, $r->sku ?? null);
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
