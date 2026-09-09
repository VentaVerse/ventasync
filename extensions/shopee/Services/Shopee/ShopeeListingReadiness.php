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

/**
 * Can this product's push succeed? Answered from the ERP's own records,
 * before anything is pressed (#101): the listing's settings, the catalog
 * product, and the category's cached attribute sheet. One computation
 * shared by the listing page, the Listings and group pages' Ready column,
 * and EVERY push path, which refuse through it and nothing else (the owner,
 * 2026-09-06: Ready has to be absolutely sure). A product that reads Ready
 * cannot be turned away by this side; what Shopee alone knows arrives as
 * the push's failure and lives in the Last sync column.
 *
 * Two checklists, because two verbs:
 *   not linked  add_item: enabled, name, description, price, image, parcel,
 *               category, couriers, the category's sheet, its required
 *               attributes, and the knowable variation refusals (#192).
 *   linked      update_item: enabled, name, description, parcel (#191).
 *               Price and stock have their own syncs and are not judged.
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
     * @param array{category_id?: int|null, logistic_ids?: array<int>|null, markup_percent?: float|null, markup_fixed?: float|null, attributes?: array} $shared
     *        Settings a group push states for the whole batch (#151): a
     *        category, couriers, price rule or attribute answers given
     *        here stand in for each listing's own, so readiness is judged
     *        on what will actually be sent.
     * @return array<int, array{ready: bool, missing: array<string>, gaps: list<array{code: string, label: string}>, linked: bool}>
     *         `missing` is the sentences the pages print; `gaps` says the
     *         same thing in codes (ListingState::GAP_*), so deep-links and
     *         engines key on a code instead of substring-matching English.
     */
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

        /* The fallback (the owner, 2026-09-05): a store that has never fetched
         * its categories or couriers cannot pick either, and "at least one
         * courier" would send the operator to an empty picker. Say what
         * is actually missing and where it comes from. */
        $categoriesCached = ShopeeCategory::query()->exists();
        $couriersCached = ShopeeLogistic::query()->exists();

        $pfx = (string) config('catalog.prefix');

        $linked = ShopeeProductLink::query()->whereIn('product_id', $productIds)->pluck('product_id')->map(fn ($v) => (int) $v)->flip();

        $listings = ShopeeListing::query()
            ->whereIn('product_id', $productIds)
            ->get()
            ->keyBy(fn ($l) => (int) $l->product_id);

        /* The parcel (#191): Shopee refuses a push - and an update - of an
         * item without a weight and package size, and it says so only
         * after the call. The listing's own parcel wins over the catalog's,
         * the way every push builds it. */
        $parcels = DB::table($pfx . 'product')
            ->whereIn('product_id', $productIds)
            ->get(['product_id', 'weight', 'length', 'width', 'height'])
            ->keyBy('product_id');

        /* A listing that arranges its own pictures answers the image gap
           itself (critique 2026-09-08); it is read off the listings already
           in hand, so this costs no query. */
        $ownPictures = $listings
            ->filter(fn ($l) => ! empty($l->image_order))
            ->keys()->all();

        /* ANY_SKU, not SKU (the owner, 2026-09-09). Shopee takes no parent SKU
           of its own, so a product carrying only its variations' codes is
           perfectly pushable - the shop indexes every model_sku and the
           link check searches on them. A product carrying no code at all
           is not: lose its link and nothing can match it back, and the
           next push builds a second listing beside the first. */
        $catalog = CatalogGaps::forProducts($productIds, [
            CatalogGaps::ENABLED, CatalogGaps::NAME, CatalogGaps::DESCRIPTION, CatalogGaps::PRICE,
            CatalogGaps::IMAGE, CatalogGaps::ANY_SKU,
        ], $ownPictures);

        /* A group is a preset the product inherits (the owner, 2026-09-05):
         * a blank category, courier list, price rule or attribute answer
         * on the listing is filled from its group, the same way every
         * push fills it now. */
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

        $out = [];
        foreach ($productIds as $productId) {
            // No row yet: judged as the blank row a push would make, with the group's presets filled in.
            $saved = $listings->get($productId) ?? (new ShopeeListing())->forceFill(['product_id' => $productId]);
            $listing = $inherit->fill($saved, $inherited[$productId] ?? null)['listing'];
            if ($listing && $sharedRule) {
                $listing->markup_percent = $shared['markup_percent'] ?? null;
                $listing->markup_fixed = $shared['markup_fixed'] ?? null;
            }
            $isLinked = $linked->has($productId);
            $gaps = [];

            // The catalog's answers, less the ones the listing answers itself.
            foreach ($catalog[$productId] ?? [] as $gap) {
                if ($gap['code'] === ListingState::GAP_NAME && trim((string) ($listing->item_name ?? '')) !== '') {
                    continue;
                }
                if ($gap['code'] === ListingState::GAP_DESCRIPTION && trim((string) ($listing->description ?? '')) !== '') {
                    continue;
                }
                if ($isLinked && in_array($gap['code'], [ListingState::GAP_PRICE, ListingState::GAP_IMAGE], true)) {
                    continue; // update_item carries neither
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

            $categoryId = $sharedCategory ?: (int) ($listing->shopee_category_id ?? 0);
            $logisticIds = $sharedLogistics ?? (!empty($listing?->logistic_ids) ? $listing->logistic_ids : []);

            if (!$categoryId) {
                $gaps[] = ['code' => ListingState::GAP_CATEGORY, 'label' => $categoriesCached ? 'a Shopee category' : 'a Shopee category - none have been fetched for this store yet (Set up store on Settings > Connection, or fetch them on Catalog > Categories)'];
            }
            if (empty($logisticIds)) {
                $gaps[] = ['code' => ListingState::GAP_COURIERS, 'label' => $couriersCached ? 'at least one courier' : 'a courier - none have been fetched for this store yet (Set up store on Settings > Connection, or fetch them on Catalog > Logistics)'];
            }

            if ($categoryId) {
                $template = $templates->get($categoryId);
                if (!$template) {
                    $gaps[] = ['code' => ListingState::GAP_SHEET, 'label' => "its category's attribute sheet (open the listing page once to read it)"];
                } else {
                    $answers = array_filter((array) ($listing?->attribute_values ?? []), fn ($v) => trim((string) (is_array($v) ? implode('', $v) : $v)) !== '');
                    $unanswered = $this->missingRequiredAttributes($template, $sharedAnswers + $answers);
                    if (!empty($unanswered)) {
                        $gaps[] = ['code' => ListingState::GAP_ATTRIBUTES,
                            'label' => 'answers for ' . count($unanswered) . ' required '
                                . Str::plural('attribute', count($unanswered))
                                . ' (' . implode(', ', array_slice($unanswered, 0, 5))
                                . (count($unanswered) > 5 ? ' and more' : '') . ')',
                            // Per-attribute names, so a page can name one
                            // fix per ATTRIBUTE (cleanup point 9).
                            'attributes' => $unanswered];
                    }
                }
            }

            /* #192: what Shopee will knowably refuse about the variations
             * (the 5x price spread after the price rule, the 50-variation
             * cap) is a gap here, so it stops a push at the door and never
             * leaves a half-listed parent. */
            $priceFor = $listing
                ? fn (float $core) => $listing->priceFor($core)
                : fn (float $core) => $core;
            if ($refusal = $variations->refusalFor($productId, $priceFor)) {
                $gaps[] = ['code' => ListingState::GAP_VARIATIONS, 'label' => 'variations Shopee accepts: ' . rtrim($refusal, '.')];
            }

            $missing = array_map(fn ($g) => $g['label'], $gaps);
            $out[$productId] = ['ready' => empty($gaps), 'missing' => $missing, 'gaps' => $gaps, 'linked' => false];
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
