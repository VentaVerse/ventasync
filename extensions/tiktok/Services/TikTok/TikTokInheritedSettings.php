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

        // This store's groups, named outright: the pivot carries no store.
        $rows = DB::table('tiktok_product_group_products as pv')
            ->joinSub(TikTokProductGroup::query()->forStore(\Extensions\tiktok\Models\TikTokSetting::defaultStore())->select(['id', 'name', 'tiktok_category_id']), 'g', 'g.id', '=', 'pv.tiktok_product_group_id')
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
                'attributes' => [],
            ];
        }
        /* The group's attribute answers ride along (the owner, 2026-09-06, the
         * Ready column): readiness and both push paths count an attribute
         * answered when the listing or its group has it. */
        $groupIds = array_values(array_unique(array_map(fn ($g) => $g['group_id'], $out)));
        if ($groupIds !== []) {
            $answers = \Extensions\tiktok\Models\TikTokProductGroupAttribute::query()->whereIn('tiktok_product_group_id', $groupIds)
                ->get(['tiktok_product_group_id', 'attribute_key', 'value'])->groupBy('tiktok_product_group_id');
            foreach ($out as $pid => $g) {
                $out[$pid]['attributes'] = ($answers->get($g['group_id']) ?? collect())->pluck('value', 'attribute_key')->all();
            }
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
        // The listing's own answer to an attribute wins over the group's.
        $own = array_filter((array) ($copy->attribute_values ?? []), fn ($v) => trim((string) (is_array($v) ? implode('', $v) : $v)) !== '');
        $groupAnswers = array_filter((array) ($group['attributes'] ?? []), fn ($v) => trim((string) $v) !== '');
        if ($groupAnswers !== [] && array_diff_key($groupAnswers, $own) !== []) {
            $copy->attribute_values = $own + $groupAnswers;
            $inherited['attributes'] = $group['group_name'];
        }

        return ['listing' => $copy, 'inherited' => $inherited];
    }

    /**
     * The price a push sends: the listing's own rule when it has one,
     * otherwise its group's, otherwise the catalog price. Every push path
     * prices through this, so a product goes up at one price whichever
     * page sent it.
     *
     * @return \Closure(float): float
     */
    public static function priceRule(?TikTokListing $listing, ?TikTokProductGroup $group): \Closure
    {
        return function (float $base) use ($listing, $group): float {
            if ($listing && ($listing->markup_percent !== null || $listing->markup_fixed !== null)) {
                return $listing->priceFor($base);
            }

            return $group ? $group->applyMarkup($base) : $base;
        };
    }

    /** The product's group on this store, by its one membership. */
    public function groupModel(int $productId): ?TikTokProductGroup
    {
        $id = $this->forProducts([$productId])[$productId]['group_id'] ?? null;

        return $id ? TikTokProductGroup::find($id) : null;
    }
}
