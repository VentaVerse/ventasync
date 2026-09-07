<?php

namespace Extensions\shopee\Services\Shopee;

use Extensions\shopee\Models\ShopeeListing;
use Extensions\shopee\Models\ShopeeProductGroup;
use Extensions\shopee\Models\ShopeeProductGroupAttribute;
use Illuminate\Support\Facades\DB;

/**
 * A group is a preset the product inherits (the owner, 2026-09-05): wherever
 * a listing's own requirement is blank, its group's stands in, on every
 * push and in readiness alike. Before this a group's category rode only
 * a group push, so the workbench called a grouped product "missing a
 * category" while the group page pushed it fine.
 *
 * Inherited: the category, the couriers, the brand, the attribute answers
 * and the price rule (2026-09-06): a listing with no rule of its own
 * prices the way its group does.
 *
 * One product, one group per store, so there is at most one answer.
 */
final class ShopeeInheritedSettings
{
    /**
     * @param list<int> $productIds
     * @return array<int, array{group_id:int, group_name:string, category_id:?int, logistic_ids:list<int>, brand_id:?int, markup_fixed:?float, markup_percent:?float, attributes:array<string,string>}>
     */
    public function forProducts(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === []) {
            return [];
        }

        // The store's own groups (the model's global scope), joined to the pivot.
        $rows = DB::table('shopee_product_group_products as pv')
            ->joinSub(ShopeeProductGroup::query()->select(['id', 'name', 'shopee_category_id', 'shopee_brand_id', 'logistic_ids', 'markup_fixed', 'markup_percent']), 'g', 'g.id', '=', 'pv.shopee_product_group_id')
            ->whereIn('pv.product_id', $productIds)
            ->orderBy('pv.id')
            ->get(['pv.product_id', 'g.id as group_id', 'g.name', 'g.shopee_category_id', 'g.shopee_brand_id', 'g.logistic_ids', 'g.markup_fixed', 'g.markup_percent']);

        $out = [];
        foreach ($rows as $row) {
            $pid = (int) $row->product_id;
            if (isset($out[$pid])) {
                continue; // one product, one group; the oldest membership wins, as the migration ruled
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
        /* The group's attribute answers ride along (the owner, 2026-09-06, the
         * Ready column), the way Lazada's and TikTok's do; fill() read them
         * and the markup from a row that never carried either. */
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

    /**
     * The listing with its blanks filled from the group, as an in-memory
     * copy that is never saved: the listing's own record stays the truth
     * of what the operator chose. `inherited` names what came from where.
     *
     * @return array{listing: ShopeeListing, inherited: array<string,string>}
     */
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
        /* The price rule and the attribute answers are presets too
         * (2026-09-06, the Ready column): a listing with no rule of its own
         * prices the way its group does, exactly as Lazada's builder has
         * always done, and a listing's own answer to an attribute wins over
         * the group's. Readiness and every push read the same filled copy. */
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
