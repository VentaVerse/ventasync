<?php

namespace Extensions\shopee\Services\Shopee;

use App\Integrations\Listings\CatalogGaps;
use App\Integrations\Listings\ListingState;
use Extensions\shopee\Models\ShopeeCategory;
use Extensions\shopee\Models\ShopeeCategoryTemplate;
use Extensions\shopee\Models\ShopeeListing;
use Extensions\shopee\Models\ShopeeLogistic;
use Extensions\shopee\Models\ShopeeProductLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ShopeeListingReadiness
{
    public function forProducts(array $productIds, array $shared = []): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === []) {
            return [];
        }
        $sharedCategory = (int) ($shared['category_id'] ?? 0) ?: null;
        $sharedLogistics = !empty($shared['logistic_ids']) ? array_map('intval', (array) $shared['logistic_ids']) : null;
        $sharedRule = array_key_exists('markup_percent', $shared) || array_key_exists('markup_fixed', $shared);
        $sharedAnswers = array_filter((array) ($shared['attributes'] ?? []), fn ($v) => trim((string) $v) !== '');

        $categoriesCached = ShopeeCategory::query()->exists();
        $couriersCached = ShopeeLogistic::query()->exists();

        $pfx = (string) config('catalog.prefix');

        $linked = ShopeeProductLink::query()->whereIn('product_id', $productIds)->pluck('product_id')->map(fn ($v) => (int) $v)->flip();

        $listings = ShopeeListing::query()
            ->whereIn('product_id', $productIds)
            ->get()
            ->keyBy(fn ($l) => (int) $l->product_id);

        $parcels = DB::table($pfx . 'product')
            ->whereIn('product_id', $productIds)
            ->get(['product_id', 'weight', 'length', 'width', 'height'])
            ->keyBy('product_id');

        $ownPictures = $listings
            ->filter(fn ($l) => ! empty($l->image_order))
            ->keys()->all();

        $catalog = CatalogGaps::forProducts($productIds, [
            CatalogGaps::ENABLED, CatalogGaps::NAME, CatalogGaps::DESCRIPTION, CatalogGaps::PRICE,
            CatalogGaps::IMAGE, CatalogGaps::ANY_SKU,
        ], $ownPictures);

        $inherit = app(ShopeeInheritedSettings::class);
        $inherited = $inherit->forProducts($productIds);

        $categoryIds = $listings->pluck('shopee_category_id')
            ->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();
        foreach ($inherited as $g) {
            if ($g['category_id']) {
                $categoryIds[] = $g['category_id'];
            }
        }
        $categoryIds = array_values(array_unique($categoryIds));
        if ($sharedCategory) {
            $categoryIds = array_values(array_unique(array_merge($categoryIds, [$sharedCategory])));
        }
        $templates = !empty($categoryIds)
            ? ShopeeCategoryTemplate::query()->whereIn('category_id', $categoryIds)->get()->keyBy('category_id')
            : collect();

        $variations = app(ShopeeVariationPush::class);
        $withVariations = ShopeeListing::withVariations($productIds);
        $shopeeStoreId = (int) (\Extensions\shopee\Models\ShopeeSetting::defaultStore()?->id ?? 0);

        $out = [];
        foreach ($productIds as $productId) {
            $saved = $listings->get($productId) ?? (new ShopeeListing())->forceFill(['product_id' => $productId]);
            $listing = $inherit->fill($saved, $inherited[$productId] ?? null)['listing'];
            if ($listing && $sharedRule && $saved->markup_percent === null && $saved->markup_fixed === null) {
                $listing->markup_percent = $shared['markup_percent'] ?? null;
                $listing->markup_fixed = $shared['markup_fixed'] ?? null;
            }
            $isLinked = $linked->has($productId);
            $gaps = [];

            foreach ($catalog[$productId] ?? [] as $gap) {
                if ($gap['code'] === ListingState::GAP_NAME && trim((string) ($listing->item_name ?? '')) !== '') {
                    continue;
                }
                if ($gap['code'] === ListingState::GAP_DESCRIPTION && trim((string) ($listing->description ?? '')) !== '') {
                    continue;
                }
                if ($isLinked && in_array($gap['code'], [ListingState::GAP_PRICE, ListingState::GAP_IMAGE], true)) {
                    continue;
                }
                if ($gap['code'] === ListingState::GAP_PRICE && $listing->startingPrice(0.0, isset($withVariations[$productId])) > 0) {
                    continue;
                }
                $gaps[] = $gap;
            }

            $parcel = $parcels->get($productId);
            $weight = (float) (($listing?->weight) ?? ($parcel->weight ?? 0));
            $length = (float) (($listing?->package_length) ?? ($parcel->length ?? 0));
            $width = (float) (($listing?->package_width) ?? ($parcel->width ?? 0));
            $height = (float) (($listing?->package_height) ?? ($parcel->height ?? 0));
            if ($weight <= 0 || $length <= 0 || $width <= 0 || $height <= 0) {
                $gaps[] = ['code' => ListingState::GAP_PARCEL, 'label' => 'a package weight and size (L×W×H) on the catalog product'];
            }

            if ($isLinked) {
                $missing = array_map(fn ($g) => $g['label'], $gaps);
                $out[$productId] = ['ready' => $gaps === [], 'missing' => $missing, 'gaps' => $gaps, 'linked' => true];
                continue;
            }

            $categoryId = (int) ($listing->shopee_category_id ?? 0) ?: (int) $sharedCategory;
            $logisticIds = !empty($listing?->logistic_ids) ? $listing->logistic_ids : ($sharedLogistics ?? []);

            if (!$categoryId) {
                $gaps[] = ['code' => ListingState::GAP_CATEGORY, 'label' => $categoriesCached ? 'a Shopee category' : 'a Shopee category - none have been fetched for this store yet (Set up store on Settings > Connection, or fetch them on Catalog > Categories)'];
            }
            if (empty($logisticIds)) {
                $gaps[] = ['code' => ListingState::GAP_COURIERS, 'label' => $couriersCached ? 'at least one courier' : 'a courier - none have been fetched for this store yet (Set up store on Settings > Connection, or fetch them on Catalog > Logistics)'];
            }

            if ($categoryId) {
                $template = $templates->get($categoryId);
                if (!$template) {
                    $gaps[] = ['code' => ListingState::GAP_SHEET, 'label' => "its category's attribute sheet (Refresh reads it)"];
                } else {
                    $answers = array_filter((array) ($listing?->attribute_values ?? []), fn ($v) => trim((string) (is_array($v) ? implode('', $v) : $v)) !== '');
                    $unanswered = $this->missingRequiredAttributes($template, $answers + $sharedAnswers);
                    if (!empty($unanswered)) {
                        $gaps[] = ['code' => ListingState::GAP_ATTRIBUTES,
                            'label' => 'answers for ' . count($unanswered) . ' required '
                                . Str::plural('attribute', count($unanswered))
                                . ' (' . implode(', ', array_slice($unanswered, 0, 5))
                                . (count($unanswered) > 5 ? ' and more' : '') . ')',
                            'attributes' => $unanswered];
                    }
                }
            }

            $priceFor = $listing
                ? fn (float $core) => $listing->priceFor($core)
                : fn (float $core) => $core;
            if ($refusal = $variations->refusalFor($productId, $priceFor, $shopeeStoreId)) {
                $gaps[] = ['code' => ListingState::GAP_VARIATIONS, 'label' => 'variations Shopee accepts: ' . rtrim($refusal, '.')];
            }

            $missing = array_map(fn ($g) => $g['label'], $gaps);
            $out[$productId] = ['ready' => empty($gaps), 'missing' => $missing, 'gaps' => $gaps, 'linked' => false];
        }

        return $out;
    }

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
