<?php

namespace Extensions\tiktok\Services\TikTok;

use App\Integrations\Listings\CatalogGaps;
use App\Integrations\Listings\ListingState;
use Extensions\tiktok\Models\TikTokCategory;
use Extensions\tiktok\Models\TikTokCategoryTemplate;
use Extensions\tiktok\Models\TikTokListing;
use Extensions\tiktok\Models\TikTokProductGroupProduct;
use Illuminate\Support\Facades\DB;

class TikTokListingReadiness
{
    public function __construct(private TikTokAttributes $attributes) {}

    public function forProducts(array $productIds): array
    {
        $categoryLabel = TikTokCategory::query()->exists() ? 'a TikTok category' : 'a TikTok category - none have been fetched for this store yet (fetch them on Catalog > Categories)';
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === []) {
            return [];
        }
        $pfx = (string) config('catalog.prefix');

        $listings = TikTokListing::query()->whereIn('product_id', $productIds)->get()->keyBy(fn ($l) => (int) $l->product_id);
        $linked = $listings->filter(fn ($l) => trim((string) ($l->tiktok_product_id ?? '')) !== '')->keys()->flip()
            ->union(TikTokProductGroupProduct::query()->onStore()->whereIn('product_id', $productIds)->whereNotNull('tiktok_product_id')->pluck('product_id')->map(fn ($v) => (int) $v)->flip());
        $inherit = app(TikTokInheritedSettings::class);
        $groups = $inherit->forProducts($productIds);
        foreach ($productIds as $pid) {
            if (! $listings->has($pid)) {
                $listings->put($pid, (new TikTokListing())->forceFill(['product_id' => $pid]));
            }
        }
        $listings = $listings->map(fn ($l) => $inherit->fill($l, $groups[(int) $l->product_id] ?? null)['listing']);
        $ownPictures = $listings->filter(fn ($l) => ! empty($l->image_order))->pluck('product_id')
            ->map(fn ($v) => (int) $v)->all();
        $catalog = CatalogGaps::forProducts($productIds, [CatalogGaps::ENABLED, CatalogGaps::IMAGE, CatalogGaps::ANY_SKU], $ownPictures);
        $categoryIds = $listings->pluck('tiktok_category_id')->filter()->unique()->values()->all();
        $templates = $categoryIds
            ? TikTokCategoryTemplate::query()->whereIn('category_id', $categoryIds)->get()->keyBy('category_id')
            : collect();
        $axes = DB::table('product_option_combination_values as cv')
            ->join('product_option_combinations as c', 'c.id', '=', 'cv.combination_id')
            ->join($pfx . 'product_option_value as pov', 'cv.product_option_value_id', '=', 'pov.product_option_value_id')
            ->whereIn('c.product_id', $productIds)
            ->groupBy('c.product_id')
            ->selectRaw('c.product_id, COUNT(DISTINCT pov.product_option_id) as axes')
            ->pluck('axes', 'product_id');

        $out = [];
        foreach ($productIds as $productId) {
            $listing = $listings->get($productId);
            $isLinked = $linked->has($productId);
            $gaps = [];

            foreach ($catalog[$productId] ?? [] as $gap) {
                if ($isLinked && $gap['code'] === ListingState::GAP_IMAGE) {
                    continue;
                }
                $gaps[] = $gap;
            }

            $categoryId = trim((string) ($listing?->tiktok_category_id ?? ''));
            if ($categoryId === '') {
                $gaps[] = ['code' => ListingState::GAP_CATEGORY, 'label' => $categoryLabel];
            } elseif (! preg_match('/^\d+$/', $categoryId)) {
                $gaps[] = ['code' => ListingState::GAP_CATEGORY, 'label' => 'a TikTok category picked again (the saved one, "' . $categoryId . '", is not a category id TikTok can read)'];
            } else {
                $template = $templates->get($categoryId);
                if (!$template) {
                    $gaps[] = ['code' => ListingState::GAP_SHEET, 'label' => "its category's attribute sheet (Refresh reads it)"];
                } else {
                    $unanswered = $this->attributes->missingRequired($this->attributes->rows($template), $listing->attribute_values ?? []);
                    if ($unanswered) {
                        $gaps[] = ['code' => ListingState::GAP_ATTRIBUTES, 'label' => 'answers for ' . count($unanswered) . ' required '
                            . (count($unanswered) === 1 ? 'attribute' : 'attributes')
                            . ' (' . implode(', ', array_slice($unanswered, 0, 3)) . (count($unanswered) > 3 ? ', ...' : '') . ')'];
                    }
                }
            }

            $axisCount = (int) ($axes[$productId] ?? 0);
            if ($axisCount > TikTokVariationPush::MAX_AXES) {
                $gaps[] = ['code' => ListingState::GAP_VARIATIONS, 'label' => 'at most ' . TikTokVariationPush::MAX_AXES . ' variation axes (this product has ' . $axisCount . ')'];
            }

            $out[$productId] = ['ready' => empty($gaps), 'missing' => array_map(fn ($g) => $g['label'], $gaps), 'gaps' => $gaps, 'linked' => $isLinked];
        }

        return $out;
    }
}
