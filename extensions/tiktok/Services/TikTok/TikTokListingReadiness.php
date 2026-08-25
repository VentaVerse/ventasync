<?php

namespace Extensions\tiktok\Services\TikTok;

use Extensions\tiktok\Models\TikTokCategoryTemplate;
use Extensions\tiktok\Models\TikTokListing;
use Illuminate\Support\Facades\DB;

/**
 * Can this product's own push to TikTok succeed? Answered from the
 * ERP's records before anything is pressed (#156, mirroring Shopee's
 * #101): the listing's category, the catalog's image, and the
 * category's cached attribute sheet. A category whose sheet has never
 * been read is NOT ready: its required attributes are unknown, and a
 * "Ready" that then fails on them is the lie this exists to kill.
 */
class TikTokListingReadiness
{
    public function __construct(private TikTokAttributes $attributes) {}

    /** @return array<int, array{ready: bool, missing: array<string>}> */
    public function forProducts(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }
        $pfx = (string) config('catalog.prefix');

        $listings = TikTokListing::query()->whereIn('product_id', $productIds)->get()->keyBy(fn ($l) => (int) $l->product_id);
        $images = DB::table($pfx . 'product')->whereIn('product_id', $productIds)->pluck('image', 'product_id');
        $categoryIds = $listings->pluck('tiktok_category_id')->filter()->unique()->values()->all();
        $templates = $categoryIds
            ? TikTokCategoryTemplate::query()->whereIn('category_id', $categoryIds)->get()->keyBy('category_id')
            : collect();

        $out = [];
        foreach ($productIds as $productId) {
            $productId = (int) $productId;
            $listing = $listings->get($productId);
            $missing = [];

            if (!$listing || !$listing->tiktok_category_id) {
                $missing[] = 'a TikTok category';
            }
            if (trim((string) ($images[$productId] ?? '')) === '') {
                $missing[] = 'a product image on the catalog product';
            }
            if ($listing && $listing->tiktok_category_id) {
                $template = $templates->get($listing->tiktok_category_id);
                if (!$template) {
                    $missing[] = "its category's attribute sheet (open the listing page once to read it)";
                } else {
                    $unanswered = $this->attributes->missingRequired($this->attributes->rows($template), $listing->attribute_values ?? []);
                    if ($unanswered) {
                        $missing[] = 'answers for ' . count($unanswered) . ' required '
                            . (count($unanswered) === 1 ? 'attribute' : 'attributes')
                            . ' (' . implode(', ', array_slice($unanswered, 0, 3)) . (count($unanswered) > 3 ? ', ...' : '') . ')';
                    }
                }
            }

            $out[$productId] = ['ready' => empty($missing), 'missing' => $missing];
        }

        return $out;
    }
}
