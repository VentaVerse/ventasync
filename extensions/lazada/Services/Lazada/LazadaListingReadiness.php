<?php

namespace Extensions\lazada\Services\Lazada;

use App\Integrations\Listings\ListingState;
use Extensions\lazada\Models\LazadaCategoryTemplate;
use Extensions\lazada\Models\LazadaProductAttribute;
use Extensions\lazada\Models\LazadaSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Can this listing's push succeed? (#101, Lazada half.) Answered from the
 * ERP's own records before anything is pressed, mirroring exactly what the
 * payload builder refuses at push time: category, brand, catalog SKU and
 * image, and the category template's required attributes. One computation
 * for the index rows, the readiness filter and the bulk upload's refusal.
 *
 * Honesty rule, same as Shopee's: a category whose template has never been
 * read is NOT ready - opening the listing reads and caches it.
 */
class LazadaListingReadiness
{
    /**
     * Missing items come back twice: as sentences (missing) for prose, and
     * as coded gaps (gaps) so deep-links and engines key on codes, never on
     * label text (#184 C, same contract as Shopee's readiness).
     *
     * @param Collection $listings LazadaProduct rows
     * @return array<int, array{ready: bool, missing: array<string>, gaps: list<array{code: string, label: string}>}> keyed by listing id
     */
    public function forListings(Collection $listings): array
    {
        $categoryLabel = \Extensions\lazada\Models\LazadaCategory::query()->exists() ? 'a Lazada category' : 'a Lazada category - none have been fetched for this store yet (fetch them on Catalog > Categories)';
        if ($listings->isEmpty()) {
            return [];
        }

        $pfx = (string) config('catalog.prefix');
        $region = (string) (LazadaSetting::defaultStore()?->region ?? '');
        $attrs = app(LazadaAttributes::class);

        /* A group is a preset the product inherits (the owner, 2026-09-05): a
         * blank category on the listing is filled from its group, on copies
         * kept in memory, the same way every push fills it now. The rows the
         * caller holds are not touched. */
        $inherit = app(LazadaInheritedSettings::class);
        $groups = $inherit->forProducts($listings->pluck('product_id')->filter()->map(fn ($v) => (int) $v)->all());
        $listings = $listings->map(fn ($l) => $inherit->fill($l, $groups[(int) $l->product_id] ?? null)['listing']);

        $products = DB::table($pfx . 'product')
            ->whereIn('product_id', $listings->pluck('product_id')->filter()->all())
            ->get(['product_id', 'sku', 'model', 'image'])
            ->keyBy('product_id');

        $templates = LazadaCategoryTemplate::query()
            ->where('region', $region)
            ->whereIn('primary_category_id', $listings->pluck('primary_category_id')->filter()->unique()->all() ?: [0])
            ->get()
            ->keyBy('primary_category_id');

        $savedAttrs = LazadaProductAttribute::query()
            ->whereIn('lazada_product_id', $listings->pluck('id')->all())
            ->get()
            ->groupBy('lazada_product_id');

        $out = [];
        foreach ($listings as $listing) {
            $missing = [];
            $gaps = [];
            $product = $products->get((int) $listing->product_id);

            if (!$listing->primary_category_id) {
                $missing[] = $categoryLabel;
                $gaps[] = ['code' => ListingState::GAP_CATEGORY, 'label' => $categoryLabel];
            }

            /* Brand is not demanded here: the payload builder defaults an
             * unchosen brand to "No Brand", exactly as Shopee's does, so a
             * listing without one pushes fine. Demanding it anyway made a
             * pushable listing read "Not ready" (#175). */

            if (!$product || (trim((string) $product->sku) === '' && trim((string) $product->model) === '')) {
                $missing[] = 'a SKU on the catalog product';
                $gaps[] = ['code' => ListingState::GAP_SKU, 'label' => 'a SKU on the catalog product'];
            }
            if (!$product || trim((string) $product->image) === '') {
                $missing[] = 'a product image on the catalog product';
                $gaps[] = ['code' => ListingState::GAP_IMAGE, 'label' => 'a product image on the catalog product'];
            }

            if ($listing->primary_category_id) {
                $template = $templates->get((int) $listing->primary_category_id);
                if (!$template || !$template->template_body) {
                    $missing[] = "its category's attribute template (open the listing once to read it)";
                    $gaps[] = ['code' => ListingState::GAP_SHEET, 'label' => "its category's attribute template"];
                } else {
                    $answered = ($savedAttrs->get($listing->id) ?? collect())
                        ->pluck('value', 'attribute_key');
                    $unanswered = [];
                    foreach ($attrs->extractAttributes($template->template_body) as $attr) {
                        if (empty($attr['required'])) {
                            continue;
                        }
                        $key = (string) ($attr['key'] ?? '');
                        if ($key === '' || $attrs->isSkuLevelRequiredKey($key)) {
                            continue;
                        }
                        if (strtolower($key) === 'brand' || LazadaAttributes::isAutoFilledAttributeKey($key)) {
                            // Brand defaults to No Brand; name, description
                            // and friends fill from the catalogue at push.
                            continue;
                        }
                        if (trim((string) ($answered[$key] ?? '')) === '') {
                            $unanswered[] = (string) ($attr['name'] ?? $key);
                        }
                    }
                    if (!empty($unanswered)) {
                        $label = 'answers for ' . count($unanswered) . ' required '
                            . \Illuminate\Support\Str::plural('attribute', count($unanswered))
                            . ' (' . implode(', ', array_slice($unanswered, 0, 5))
                            . (count($unanswered) > 5 ? ' and more' : '') . ')';
                        $missing[] = $label;
                        $gaps[] = ['code' => ListingState::GAP_ATTRIBUTES, 'label' => $label];
                    }
                }
            }

            $out[(int) $listing->id] = ['ready' => empty($missing), 'missing' => $missing, 'gaps' => $gaps];
        }

        return $out;
    }

    /**
     * Readiness for catalogue products with NO lazada_products row yet - the
     * catalogue-first index and the state engine ask about these (#184 C).
     * Everything a row would carry is by definition missing (the category,
     * and the attribute sheet behind it), so only the catalogue-level checks
     * vary per product.
     *
     * @param list<int> $productIds
     * @return array<int, array{ready: bool, missing: array<string>, gaps: list<array{code: string, label: string}>}> keyed by product id
     */
    public function forProducts(array $productIds): array
    {
        $categoryLabel = \Extensions\lazada\Models\LazadaCategory::query()->exists() ? 'a Lazada category' : 'a Lazada category - none have been fetched for this store yet (fetch them on Catalog > Categories)';
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === []) {
            return [];
        }

        $pfx = (string) config('catalog.prefix');
        $products = DB::table($pfx . 'product')
            ->whereIn('product_id', $productIds)
            ->get(['product_id', 'sku', 'model', 'image'])
            ->keyBy('product_id');

        $out = [];
        foreach ($productIds as $productId) {
            $product = $products->get($productId);
            $missing = [$categoryLabel];
            $gaps = [['code' => ListingState::GAP_CATEGORY, 'label' => $categoryLabel]];

            if (!$product || (trim((string) $product->sku) === '' && trim((string) $product->model) === '')) {
                $missing[] = 'a SKU on the catalog product';
                $gaps[] = ['code' => ListingState::GAP_SKU, 'label' => 'a SKU on the catalog product'];
            }
            if (!$product || trim((string) $product->image) === '') {
                $missing[] = 'a product image on the catalog product';
                $gaps[] = ['code' => ListingState::GAP_IMAGE, 'label' => 'a product image on the catalog product'];
            }

            $out[$productId] = ['ready' => false, 'missing' => $missing, 'gaps' => $gaps];
        }

        return $out;
    }
}
