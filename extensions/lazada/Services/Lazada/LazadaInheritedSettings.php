<?php

namespace Extensions\lazada\Services\Lazada;

use Extensions\lazada\Models\LazadaProduct;
use Extensions\lazada\Models\LazadaProductGroup;
use Illuminate\Support\Facades\DB;

final class LazadaInheritedSettings
{
    public function forProducts(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === []) {
            return [];
        }

        $rows = DB::table('lazada_product_group_products as pv')
            ->joinSub(LazadaProductGroup::query()->forStore(\Extensions\lazada\Models\LazadaSetting::defaultStore())->select(['id', 'name', 'lazada_category_id', 'brand_id', 'brand_name_override', 'markup_fixed', 'markup_percent']), 'g', 'g.id', '=', 'pv.lazada_product_group_id')
            ->whereIn('pv.product_id', $productIds)
            ->orderBy('pv.id')
            ->get(['pv.product_id', 'g.id as group_id', 'g.name', 'g.lazada_category_id', 'g.brand_id', 'g.brand_name_override', 'g.markup_fixed', 'g.markup_percent']);

        $out = [];
        foreach ($rows as $row) {
            $pid = (int) $row->product_id;
            if (isset($out[$pid])) {
                continue;
            }
            $out[$pid] = [
                'group_id' => (int) $row->group_id,
                'group_name' => (string) $row->name,
                'category_id' => (int) $row->lazada_category_id ?: null,
                'brand_id' => (int) ($row->brand_id ?? 0) ?: null,
                'brand_name' => trim((string) ($row->brand_name_override ?? '')) !== '' ? (string) $row->brand_name_override : null,
                'markup_fixed' => $row->markup_fixed !== null ? (float) $row->markup_fixed : null,
                'markup_percent' => $row->markup_percent !== null ? (float) $row->markup_percent : null,
                'attributes' => [],
            ];
        }
        $groupIds = array_values(array_unique(array_map(fn ($g) => $g['group_id'], $out)));
        if ($groupIds !== []) {
            $answers = \Extensions\lazada\Models\LazadaProductGroupAttribute::query()->whereIn('lazada_product_group_id', $groupIds)
                ->get(['lazada_product_group_id', 'attribute_key', 'value'])->groupBy('lazada_product_group_id');
            foreach ($out as $pid => $g) {
                $out[$pid]['attributes'] = ($answers->get($g['group_id']) ?? collect())->pluck('value', 'attribute_key')->all();
            }
        }

        return $out;
    }

    public function fill(LazadaProduct $listing, ?array $group): array
    {
        $copy = clone $listing;
        $inherited = [];
        if ($group === null) {
            return ['listing' => $copy, 'inherited' => $inherited];
        }

        if ((int) ($copy->primary_category_id ?? 0) <= 0 && $group['category_id']) {
            $copy->primary_category_id = $group['category_id'];
            $inherited['category'] = $group['group_name'];
        }
        if ((int) ($copy->brand_id ?? 0) <= 0 && trim((string) ($copy->brand_name_override ?? '')) === '' && ($group['brand_id'] || $group['brand_name'])) {
            $copy->brand_id = $group['brand_id'];
            $copy->brand_name_override = $group['brand_name'];
            $inherited['brand'] = $group['group_name'];
        }

        return ['listing' => $copy, 'inherited' => $inherited];
    }
}
