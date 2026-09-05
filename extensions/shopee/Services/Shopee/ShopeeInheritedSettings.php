<?php

namespace Extensions\shopee\Services\Shopee;

use Extensions\shopee\Models\ShopeeListing;
use Extensions\shopee\Models\ShopeeProductGroup;
use Illuminate\Support\Facades\DB;

/**
 * A group is a preset the product inherits (the owner, 2026-09-05): wherever
 * a listing's own requirement is blank, its group's stands in, on every
 * push and in readiness alike. Before this a group's category rode only
 * a group push, so the workbench called a grouped product "missing a
 * category" while the group page pushed it fine.
 *
 * Inherited: the category, the couriers and the brand. The price rule is
 * not: a listing at catalog price is a choice a group must not overturn
 * silently, so a group's markup still rides only a group push.
 *
 * One product, one group per store, so there is at most one answer.
 */
final class ShopeeInheritedSettings
{
    /**
     * @param list<int> $productIds
     * @return array<int, array{group_id:int, group_name:string, category_id:?int, logistic_ids:list<int>, brand_id:?int}>
     */
    public function forProducts(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === []) {
            return [];
        }

        // The store's own groups (the model's global scope), joined to the pivot.
        $rows = DB::table('shopee_product_group_products as pv')
            ->joinSub(ShopeeProductGroup::query()->select(['id', 'name', 'shopee_category_id', 'shopee_brand_id', 'logistic_ids']), 'g', 'g.id', '=', 'pv.shopee_product_group_id')
            ->whereIn('pv.product_id', $productIds)
            ->orderBy('pv.id')
            ->get(['pv.product_id', 'g.id as group_id', 'g.name', 'g.shopee_category_id', 'g.shopee_brand_id', 'g.logistic_ids']);

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
            ];
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

        return ['listing' => $copy, 'inherited' => $inherited];
    }
}
