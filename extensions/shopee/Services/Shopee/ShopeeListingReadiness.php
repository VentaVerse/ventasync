<?php

namespace Extensions\shopee\Services\Shopee;

use Extensions\shopee\Models\ShopeeCategoryTemplate;
use Extensions\shopee\Models\ShopeeListing;
use Illuminate\Support\Facades\DB;

/**
 * Can this product's one-click push succeed? Answered from the ERP's own
 * records, before anything is pressed (#101): the listing's settings, the
 * catalog's image, and the category's cached attribute sheet. One
 * computation shared by the listing page, the listings index and the bulk
 * push, so "Ready" means the same thing everywhere it appears.
 *
 * Honesty rule: a category whose sheet has never been read is NOT ready -
 * we cannot know its required attributes, and a "Ready" that then fails on
 * them is the exact lie this exists to kill. Opening the listing page
 * reads and caches the sheet.
 */
class ShopeeListingReadiness
{
    /**
     * @param array<int> $productIds
     * @return array<int, array{ready: bool, missing: array<string>}>
     */
    /**
     * @param array{category_id?: int|null, logistic_ids?: array<int>|null} $shared
     *        Settings a combined push states for the whole batch (#151):
     *        a category or couriers given here stand in for each
     *        listing's own, so readiness is judged on what will actually
     *        be sent.
     */
    public function forProducts(array $productIds, array $shared = []): array
    {
        if (empty($productIds)) {
            return [];
        }
        $sharedCategory = (int) ($shared['category_id'] ?? 0) ?: null;
        $sharedLogistics = !empty($shared['logistic_ids']) ? array_map('intval', (array) $shared['logistic_ids']) : null;

        $pfx = (string) config('catalog.prefix');

        $listings = ShopeeListing::query()
            ->whereIn('product_id', $productIds)
            ->get()
            ->keyBy(fn ($l) => (int) $l->product_id);

        $images = DB::table($pfx . 'product')
            ->whereIn('product_id', $productIds)
            ->pluck('image', 'product_id');

        $categoryIds = $listings->pluck('shopee_category_id')
            ->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();
        if ($sharedCategory) {
            $categoryIds = array_values(array_unique(array_merge($categoryIds, [$sharedCategory])));
        }
        $templates = !empty($categoryIds)
            ? ShopeeCategoryTemplate::query()->whereIn('category_id', $categoryIds)->get()->keyBy('category_id')
            : collect();

        $out = [];
        foreach ($productIds as $productId) {
            $productId = (int) $productId;
            $listing = $listings->get($productId);
            $missing = [];

            $categoryId = $sharedCategory ?: (int) ($listing->shopee_category_id ?? 0);
            $logisticIds = $sharedLogistics ?? ($listing->logistic_ids ?? []);

            if (!$categoryId) {
                $missing[] = 'a Shopee category';
            }
            if (empty($logisticIds)) {
                $missing[] = 'at least one courier';
            }
            if (trim((string) ($images[$productId] ?? '')) === '') {
                $missing[] = 'a product image on the catalog product';
            }

            if ($categoryId) {
                $template = $templates->get($categoryId);
                if (!$template) {
                    $missing[] = "its category's attribute sheet (open the listing page once to read it)";
                } else {
                    $unanswered = $this->missingRequiredAttributes($template, $listing?->attribute_values ?? []);
                    if (!empty($unanswered)) {
                        $missing[] = 'answers for ' . count($unanswered) . ' required '
                            . \Illuminate\Support\Str::plural('attribute', count($unanswered))
                            . ' (' . implode(', ', array_slice($unanswered, 0, 5))
                            . (count($unanswered) > 5 ? ' and more' : '') . ')';
                    }
                }
            }

            $out[$productId] = ['ready' => empty($missing), 'missing' => $missing];
        }

        return $out;
    }

    /** The names of required attributes the listing has not answered. */
    public function missingRequiredAttributes(ShopeeCategoryTemplate $template, array $saved): array
    {
        $raw = is_array($template->attributes) ? $template->attributes : (json_decode((string) $template->attributes, true) ?: []);
        $list = $raw['attribute_list'] ?? $raw['attribute_tree'] ?? $raw['attributes'] ?? $raw;
        if (!is_array($list)) {
            return [];
        }

        $unanswered = [];
        foreach ($list as $attr) {
            if (!is_array($attr) || empty($attr['is_mandatory'] ?? $attr['mandatory'] ?? false)) {
                continue;
            }
            $id = (string) ($attr['attribute_id'] ?? '');
            $name = (string) ($attr['display_attribute_name'] ?? $attr['original_attribute_name'] ?? $attr['attribute_name'] ?? $id);
            $value = trim((string) ($saved[$id] ?? $saved[$name] ?? ''));
            if ($value === '') {
                $unanswered[] = $name !== '' ? $name : ('attribute ' . $id);
            }
        }

        return $unanswered;
    }
}
