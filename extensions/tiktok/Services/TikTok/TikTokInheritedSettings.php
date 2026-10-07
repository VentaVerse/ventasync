<?php

namespace Extensions\tiktok\Services\TikTok;

use Extensions\tiktok\Models\TikTokListing;
use Extensions\tiktok\Models\TikTokProductGroup;
use Illuminate\Support\Facades\DB;

final class TikTokInheritedSettings
{
    public function forProducts(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === []) {
            return [];
        }

        $rows = DB::table('tiktok_product_group_products as pv')
            ->joinSub(TikTokProductGroup::query()->forStore(\Extensions\tiktok\Models\TikTokSetting::defaultStore())->select(['id', 'name', 'tiktok_category_id']), 'g', 'g.id', '=', 'pv.tiktok_product_group_id')
            ->whereIn('pv.product_id', $productIds)
            ->orderBy('pv.id')
            ->get(['pv.product_id', 'g.id as group_id', 'g.name', 'g.tiktok_category_id']);

        $out = [];
        foreach ($rows as $row) {
            $pid = (int) $row->product_id;
            if (isset($out[$pid])) {
                continue;
            }
            $out[$pid] = [
                'group_id' => (int) $row->group_id,
                'group_name' => (string) $row->name,
                'category_id' => trim((string) ($row->tiktok_category_id ?? '')) !== '' ? (string) $row->tiktok_category_id : null,
                'attributes' => [],
            ];
        }
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

    public function fill(TikTokListing $listing, ?array $group): array
    {
        $copy = clone $listing;
        $inherited = [];
        if ($group && trim((string) ($copy->tiktok_category_id ?? '')) === '' && $group['category_id']) {
            $copy->tiktok_category_id = $group['category_id'];
            $inherited['category'] = $group['group_name'];
        }
        $own = array_filter((array) ($copy->attribute_values ?? []), fn ($v) => trim((string) (is_array($v) ? implode('', $v) : $v)) !== '');
        $groupAnswers = array_filter((array) ($group['attributes'] ?? []), fn ($v) => trim((string) $v) !== '');
        if ($groupAnswers !== [] && array_diff_key($groupAnswers, $own) !== []) {
            $copy->attribute_values = $own + $groupAnswers;
            $inherited['attributes'] = $group['group_name'];
        }

        return ['listing' => $copy, 'inherited' => $inherited];
    }

    public static function priceRule(?TikTokListing $listing, ?TikTokProductGroup $group): \Closure
    {
        return function (float $base) use ($listing, $group): float {
            if ($listing && ($listing->markup_percent !== null || $listing->markup_fixed !== null)) {
                return $listing->priceFor($base);
            }

            return $group ? $group->applyMarkup($base) : $base;
        };
    }

    public function groupModel(int $productId): ?TikTokProductGroup
    {
        $id = $this->forProducts([$productId])[$productId]['group_id'] ?? null;

        return $id ? TikTokProductGroup::find($id) : null;
    }
}
