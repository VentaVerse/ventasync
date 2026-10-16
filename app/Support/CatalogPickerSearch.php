<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class CatalogPickerSearch
{
    public static function items(string $q, callable $linkedLabelFor, int $limit = 20): array
    {
        $q = trim($q);
        if (strlen($q) < 2) {
            return [];
        }
        $langId = (int) config('catalog.default_language_id');

        $valueMatchIds = DB::table('product_variation_values')
            ->where('sku', 'like', '%' . $q . '%')->whereNotNull('sku')->where('sku', '!=', '')
            ->pluck('product_id')->unique()->all();
        $comboMatchIds = DB::table('product_variations')
            ->where('sku', 'like', '%' . $q . '%')->whereNotNull('sku')->where('sku', '!=', '')
            ->pluck('product_id')->unique()->all();

        $rows = DB::table('products as p')
            ->leftJoin('product_descriptions as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')->where('pd.language_id', '=', $langId);
            })
            ->where(function ($sub) use ($q, $valueMatchIds, $comboMatchIds) {
                $sub->where('pd.name', 'like', '%' . $q . '%')
                    ->orWhere('p.sku', 'like', '%' . $q . '%');
                $ids = array_values(array_unique(array_merge($valueMatchIds, $comboMatchIds)));
                if ($ids !== []) {
                    $sub->orWhereIn('p.product_id', $ids);
                }
            })
            ->orderByDesc('p.product_id')
            ->limit($limit)
            ->get(['p.product_id', 'pd.name', 'p.sku', 'p.image']);
        if ($rows->isEmpty()) {
            return [];
        }
        $productIds = $rows->pluck('product_id')->map(fn ($v) => (int) $v)->all();

        $variationValues = DB::table('product_variation_values as pov')
            ->leftJoin('variation_value_descriptions as ovd', function ($j) use ($langId) {
                $j->on('pov.variation_value_id', '=', 'ovd.variation_value_id')->where('ovd.language_id', '=', $langId);
            })
            ->whereIn('pov.product_id', $productIds)
            ->whereNotNull('pov.sku')->where('pov.sku', '!=', '')
            ->get(['pov.product_id', 'pov.sku', 'pov.quantity', 'ovd.name as value_name'])
            ->groupBy('product_id');
        $combos = DB::table('product_variations')
            ->whereIn('product_id', $productIds)->whereNotNull('sku')->where('sku', '!=', '')
            ->get(['product_id', 'sku'])->groupBy('product_id');
        $linked = $linkedLabelFor($productIds);

        return $rows->map(function ($r) use ($variationValues, $combos, $linked) {
            $pid = (int) $r->product_id;
            $variationList = ($variationValues->get($pid) ?? collect())->map(fn ($ov) => [
                'sku' => (string) $ov->sku,
                'name' => (string) ($ov->value_name ?? ''),
                'qty' => (int) $ov->quantity,
            ])->values()->all();
            $skus = array_values(array_unique(array_filter(array_merge(
                [trim((string) ($r->sku ?? ''))],
                array_column($variationList, 'sku'),
                ($combos->get($pid) ?? collect())->pluck('sku')->map(fn ($v) => trim((string) $v))->all()
            ))));

            return [
                'product_id' => $pid,
                'name' => html_entity_decode((string) ($r->name ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'sku' => (string) ($r->sku ?? ''),
                'image' => trim((string) ($r->image ?? '')) !== '' ? \App\Services\Media\ImageCache::url((string) $r->image) : null,
                'variations' => $variationList,
                'skus' => $skus,
                'linked' => $linked[$pid] ?? null,
            ];
        })->values()->all();
    }
}
