<?php

namespace Extensions\tiktok\Services\TikTok;

use Extensions\tiktok\Models\TikTokListing;
use Extensions\tiktok\Models\TikTokProductGroup;
use Illuminate\Support\Facades\DB;

/**
 * A group is a preset the product inherits (the owner, 2026-09-05), the
 * Shopee shape on TikTok: wherever a listing's own category is blank,
 * its group's stands in, on every push and in readiness alike. The
 * price rule is not inherited: a group's markup rides only a group push.
 */
final class TikTokInheritedSettings
{
    /**
     * @param list<int> $productIds
     * @return array<int, array{group_id:int, group_name:string, category_id:?string}>
     */
    public function forProducts(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === []) {
            return [];
        }

        $rows = DB::table('tiktok_product_group_products as pv')
            ->joinSub(TikTokProductGroup::query()->select(['id', 'name', 'tiktok_category_id']), 'g', 'g.id', '=', 'pv.tiktok_product_group_id')
            ->whereIn('pv.product_id', $productIds)
            ->orderBy('pv.id')
            ->get(['pv.product_id', 'g.id as group_id', 'g.name', 'g.tiktok_category_id']);

        $out = [];
        foreach ($rows as $row) {
            $pid = (int) $row->product_id;
            if (isset($out[$pid])) {
                continue; // one product, one group; the oldest membership wins
            }
            $out[$pid] = [
                'group_id' => (int) $row->group_id,
                'group_name' => (string) $row->name,
                'category_id' => trim((string) ($row->tiktok_category_id ?? '')) !== '' ? (string) $row->tiktok_category_id : null,
            ];
        }

        return $out;
    }

    /**
     * The listing with its blanks filled, as an in-memory copy never saved.
     *
     * @return array{listing: TikTokListing, inherited: array<string,string>}
     */
    public function fill(TikTokListing $listing, ?array $group): array
    {
        $copy = clone $listing;
        $inherited = [];
        if ($group && trim((string) ($copy->tiktok_category_id ?? '')) === '' && $group['category_id']) {
            $copy->tiktok_category_id = $group['category_id'];
            $inherited['category'] = $group['group_name'];
        }

        return ['listing' => $copy, 'inherited' => $inherited];
    }
}
