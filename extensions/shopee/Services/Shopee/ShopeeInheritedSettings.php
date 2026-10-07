<?php

namespace Extensions\shopee\Services\Shopee;

use Extensions\shopee\Models\ShopeeListing;
use Extensions\shopee\Models\ShopeeProductGroup;
use Extensions\shopee\Models\ShopeeProductGroupAttribute;
use Illuminate\Support\Facades\DB;

final class ShopeeInheritedSettings
{
    public function forProducts(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === []) {
            return [];
        }

        $rows = DB::table('shopee_product_group_products as pv')
            ->joinSub(ShopeeProductGroup::query()->forStore(\Extensions\shopee\Models\ShopeeSetting::defaultStore())->select(['id', 'name', 'shopee_category_id', 'shopee_brand_id', 'logistic_ids', 'markup_fixed', 'markup_percent']), 'g', 'g.id', '=', 'pv.shopee_product_group_id')
            ->whereIn('pv.product_id', $productIds)
            ->orderBy('pv.id')
            ->get(['pv.product_id', 'g.id as group_id', 'g.name', 'g.shopee_category_id', 'g.shopee_brand_id', 'g.logistic_ids', 'g.markup_fixed', 'g.markup_percent']);

        $out = [];
        foreach ($rows as $row) {
            $pid = (int) $row->product_id;
            if (isset($out[$pid])) {
                continue;
            }
            $logistics = is_string($row->logistic_ids) ? (json_decode($row->logistic_ids, true) ?: []) : (array) ($row->logistic_ids ?? []);
            $out[$pid] = [
                'group_id' => (int) $row->group_id,
                'group_name' => (string) $row->name,
                'category_id' => (int) $row->shopee_category_id ?: null,
                'logistic_ids' => array_values(array_map('intval', $logistics)),
                'brand_id' => (int) ($row->shopee_brand_id ?? 0) ?: null,
                'markup_fixed' => $row->markup_fixed !== null ? (float) $row->markup_fixed : null,
                'markup_percent' => $row->markup_percent !== null ? (float) $row->markup_percent : null,
                'attributes' => [],
            ];
        }
        $groupIds = array_values(array_unique(array_map(fn ($g) => $g['group_id'], $out)));
        if ($groupIds !== []) {
            $answers = ShopeeProductGroupAttribute::query()->whereIn('shopee_product_group_id', $groupIds)
                ->get(['shopee_product_group_id', 'attribute_key', 'value'])->groupBy('shopee_product_group_id');
            foreach ($out as $pid => $g) {
                $out[$pid]['attributes'] = ($answers->get($g['group_id']) ?? collect())->pluck('value', 'attribute_key')->all();
            }
        }

        return $out;
    }

    public function fill(ShopeeListing $listing, ?array $group): array
    {
        $copy = clone $listing;
        $inherited = [];
        if ($group === null) {
            return ['listing' => $copy, 'inherited' => $inherited];
        }

        if ((int) ($copy->shopee_category_id ?? 0) <= 0 && $group['category_id']) {
            $copy->shopee_category_id = $group['category_id'];
            $inherited['category'] = $group['group_name'];
        }
        if (empty($copy->logistic_ids) && $group['logistic_ids'] !== []) {
            $copy->logistic_ids = $group['logistic_ids'];
            $inherited['couriers'] = $group['group_name'];
        }
        if ((int) ($copy->shopee_brand_id ?? 0) <= 0 && $group['brand_id']) {
            $copy->shopee_brand_id = $group['brand_id'];
            $inherited['brand'] = $group['group_name'];
        }
        if ($copy->markup_percent === null && $copy->markup_fixed === null && ($group['markup_percent'] !== null || $group['markup_fixed'] !== null)) {
            $copy->markup_percent = $group['markup_percent'];
            $copy->markup_fixed = $group['markup_fixed'];
            $inherited['price_rule'] = $group['group_name'];
        }
        $own = array_filter((array) ($copy->attribute_values ?? []), fn ($v) => trim((string) (is_array($v) ? implode('', $v) : $v)) !== '');
        $groupAnswers = array_filter((array) ($group['attributes'] ?? []), fn ($v) => trim((string) $v) !== '');
        if ($groupAnswers !== [] && array_diff_key($groupAnswers, $own) !== []) {
            $copy->attribute_values = $own + $groupAnswers;
            $inherited['attributes'] = $group['group_name'];
        }

        return ['listing' => $copy, 'inherited' => $inherited];
    }
}
