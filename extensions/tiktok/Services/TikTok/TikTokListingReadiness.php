<?php

namespace Extensions\tiktok\Services\TikTok;

use App\Integrations\Listings\CatalogGaps;
use App\Integrations\Listings\ListingState;
use Extensions\tiktok\Models\TikTokCategory;
use Extensions\tiktok\Models\TikTokCategoryTemplate;
use Extensions\tiktok\Models\TikTokListing;
use Extensions\tiktok\Models\TikTokProductGroupProduct;
use Illuminate\Support\Facades\DB;

/**
 * Can this product's push to TikTok succeed? Answered from the ERP's
 * records before anything is pressed (#156, mirroring Shopee's #101), and
 * mirroring EXACTLY what the builder refuses before sending: the catalog
 * product enabled, a category id TikTok can read, the catalog's image
 * (for a first push), the category's cached attribute sheet and its
 * required attributes, and at most three variation axes. One computation
 * for the Ready column, the readiness filter and every push path (the owner,
 * 2026-09-06: Ready has to be absolutely sure). An update runs the same
 * builder with the ids injected, so a listed product answers the same
 * checklist less the image.
 *
 * A category whose sheet has never been read is NOT ready: its required
 * attributes are unknown, and a "Ready" that then fails on them is the
 * lie this exists to kill.
 */
class TikTokListingReadiness
{
    public function __construct(private TikTokAttributes $attributes) {}

    /**
     * Missing items come back twice: as sentences (missing) for prose -
     * byte-identical to what the guards have always said, which tests pin -
     * and as coded gaps so deep-links and the state engine key on codes,
     * never on label text (#184 D, same contract as Shopee's and Lazada's).
     *
     * @return array<int, array{ready: bool, missing: array<string>, gaps: list<array{code: string, label: string}>, linked: bool}>
     */
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
            ->union(TikTokProductGroupProduct::query()->whereIn('product_id', $productIds)->whereNotNull('tiktok_product_id')->pluck('product_id')->map(fn ($v) => (int) $v)->flip());
        /* A group is a preset the product inherits (the owner, 2026-09-05): a
         * blank category on the listing is filled from its group, in memory,
         * the same way every push fills it now, and the group's attribute
         * answers count as answered. */
        $inherit = app(TikTokInheritedSettings::class);
        $groups = $inherit->forProducts($productIds);
        // No row yet: judged as the blank row a push would make, with the group's presets filled in.
        foreach ($productIds as $pid) {
            if (! $listings->has($pid)) {
                $listings->put($pid, (new TikTokListing())->forceFill(['product_id' => $pid]));
            }
        }
        $listings = $listings->map(fn ($l) => $inherit->fill($l, $groups[(int) $l->product_id] ?? null)['listing']);
        $catalog = CatalogGaps::forProducts($productIds, [CatalogGaps::ENABLED, CatalogGaps::IMAGE]);
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
                    continue; // an edit keeps the pictures it has
                }
                $gaps[] = $gap;
            }

            $categoryId = trim((string) ($listing?->tiktok_category_id ?? ''));
            if ($categoryId === '') {
                $gaps[] = ['code' => ListingState::GAP_CATEGORY, 'label' => $categoryLabel];
            } elseif (! preg_match('/^\d+$/', $categoryId)) {
                // The builder refuses an id TikTok cannot read (2026-09-06).
                $gaps[] = ['code' => ListingState::GAP_CATEGORY, 'label' => 'a TikTok category picked again (the saved one, "' . $categoryId . '", is not a category id TikTok can read)'];
            } else {
                $template = $templates->get($categoryId);
                if (!$template) {
                    $gaps[] = ['code' => ListingState::GAP_SHEET, 'label' => "its category's attribute sheet (open the listing page once to read it)"];
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
