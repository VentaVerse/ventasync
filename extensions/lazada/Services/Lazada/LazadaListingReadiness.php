<?php

namespace Extensions\lazada\Services\Lazada;

use App\Integrations\Listings\CatalogGaps;
use App\Integrations\Listings\ListingState;
use Extensions\lazada\Models\LazadaCategory;
use Extensions\lazada\Models\LazadaCategoryTemplate;
use Extensions\lazada\Models\LazadaProductAttribute;
use Extensions\lazada\Models\LazadaProductVariant;
use Extensions\lazada\Models\LazadaSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Can this listing's push succeed? (#101, Lazada half.) Answered from the
 * ERP's own records before anything is pressed, mirroring EXACTLY what the
 * upload gate and the payload builder refuse: the catalog product enabled,
 * its SKU and image, the category, the category template's required
 * attributes (the basics the catalog fills included), every variation's
 * SKU, the category's variation-axis allowance, and a price above zero
 * when the template calls price mandatory. One computation for the Ready
 * column, the readiness filter and every push path (the owner, 2026-09-06:
 * Ready has to be absolutely sure). Lazada's update is the create with an
 * ItemId, so a listed row answers the same checklist.
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
     * @param array{settings?: array, attributes?: array} $overrides  what a group push
     *        states for the whole batch, in the builder's own shape, so
     *        readiness is judged on what will actually be sent
     * @return array<int, array{ready: bool, missing: array<string>, gaps: list<array{code: string, label: string}>}> keyed by listing id
     */
    public function forListings(Collection $listings, array $overrides = []): array
    {
        $categoryLabel = LazadaCategory::query()->exists() ? 'a Lazada category' : 'a Lazada category - none have been fetched for this store yet (fetch them on Catalog > Categories)';
        if ($listings->isEmpty()) {
            return [];
        }

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $region = (string) (LazadaSetting::defaultStore()?->region ?? '');
        $attrs = app(LazadaAttributes::class);

        /* A group is a preset the product inherits (the owner, 2026-09-05): a
         * blank category on the listing is filled from its group, on copies
         * kept in memory, the same way every push fills it now. The rows the
         * caller holds are not touched. The group's price rule stands in
         * when the listing has none (the builder's own rule), and its
         * attribute answers count as answered. */
        $inherit = app(LazadaInheritedSettings::class);
        $productIds = $listings->pluck('product_id')->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();
        $groups = $inherit->forProducts($productIds);
        $listings = $listings->map(fn ($l) => $inherit->fill($l, $groups[(int) $l->product_id] ?? null)['listing']);
        if (!empty($overrides['settings'])) {
            $listings = $listings->map(fn ($l) => (clone $l)->forceFill($overrides['settings']));
        }
        $sharedAnswers = array_filter((array) ($overrides['attributes'] ?? []), fn ($v) => trim((string) $v) !== '');

        $products = DB::table($pfx . 'product as p')
            ->leftJoin($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')->where('pd.language_id', '=', $langId);
            })
            ->whereIn('p.product_id', $productIds)
            ->get(['p.product_id', 'p.sku', 'p.model', 'p.image', 'p.price', 'pd.name', 'pd.description'])
            ->keyBy('product_id');
        $catalog = CatalogGaps::forProducts($productIds, [CatalogGaps::ENABLED, CatalogGaps::SKU, CatalogGaps::IMAGE]);

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
            $productId = (int) $listing->product_id;
            $gaps = [];
            $product = $products->get($productId);
            $group = $groups[$productId] ?? null;

            foreach ($catalog[$productId] ?? [] as $gap) {
                $gaps[] = $gap;
            }

            if (!$listing->primary_category_id) {
                $gaps[] = ['code' => ListingState::GAP_CATEGORY, 'label' => $categoryLabel];
            }

            /* Brand is not demanded here: the payload builder defaults an
             * unchosen brand to "No Brand", exactly as Shopee's does, so a
             * listing without one pushes fine. Demanding it anyway made a
             * pushable listing read "Not ready" (#175). */

            $requiredKeys = [];
            $template = $listing->primary_category_id ? $templates->get((int) $listing->primary_category_id) : null;
            if ($listing->primary_category_id) {
                if (!$template || !$template->template_body) {
                    $gaps[] = ['code' => ListingState::GAP_SHEET, 'label' => "its category's attribute template (open the listing once to read it)"];
                } else {
                    // The listing's own answers, the group's answers, then
                    // what the catalog fills at push - the builder's merge.
                    $answered = ($savedAttrs->get($listing->id) ?? collect())->pluck('value', 'attribute_key')->all();
                    foreach ((array) ($group['attributes'] ?? []) + $sharedAnswers as $k => $v) {
                        if (trim((string) ($answered[$k] ?? '')) === '' && trim((string) $v) !== '') {
                            $answered[$k] = $v;
                        }
                    }
                    $answered = $attrs->autoFillBasics($answered, $product);
                    $unanswered = [];
                    $unansweredKeys = [];
                    foreach ($attrs->extractAttributes($template->template_body) as $attr) {
                        if (empty($attr['required'])) {
                            continue;
                        }
                        $key = (string) ($attr['key'] ?? '');
                        if ($key === '') {
                            continue;
                        }
                        $requiredKeys[] = $key;
                        if ($attrs->isSkuLevelRequiredKey($key) || strtolower($key) === 'brand') {
                            continue; // brand defaults to No Brand; SKU-level keys are judged below
                        }
                        if (trim((string) ($answered[$key] ?? '')) === '') {
                            $unanswered[] = (string) ($attr['name'] ?? $key);
                            $unansweredKeys[$key] = (string) ($attr['name'] ?? $key);
                        }
                    }
                    if (!empty($unanswered)) {
                        $gaps[] = ['code' => ListingState::GAP_ATTRIBUTES, 'label' => 'answers for ' . count($unanswered) . ' required '
                            . Str::plural('attribute', count($unanswered))
                            . ' (' . implode(', ', array_slice($unanswered, 0, 5))
                            . (count($unanswered) > 5 ? ' and more' : '') . ')',
                            // key => name, so the listing page can mark each row (the builder's own words).
                            'keys' => $unansweredKeys];
                    }
                }
            }

            foreach ($this->variationGaps($listing, $product, $group, $template, $requiredKeys, $pfx, $langId) as $gap) {
                $gaps[] = $gap;
            }

            $missing = array_map(fn ($g) => $g['label'], $gaps);
            $out[(int) $listing->id] = ['ready' => empty($gaps), 'missing' => $missing, 'gaps' => $gaps];
        }

        return $out;
    }

    /**
     * What the payload builder refuses about the variations, judged the way
     * it judges them: a multi-option product with no combinations stored,
     * more option axes than the category's sale properties allow, a
     * variation without a SKU, and a price at or below zero when the
     * template calls price mandatory.
     *
     * @return list<array{code: string, label: string}>
     */
    private function variationGaps($listing, ?object $product, ?array $group, ?LazadaCategoryTemplate $template, array $requiredKeys, string $pfx, int $langId): array
    {
        $gaps = [];
        $productId = (int) $listing->product_id;
        $povRows = DB::table($pfx . 'product_option_value as pov')
            ->leftJoin($pfx . 'option_description as od', function ($j) use ($langId) {
                $j->on('pov.option_id', '=', 'od.option_id')->where('od.language_id', '=', $langId);
            })
            ->where('pov.product_id', $productId)
            ->orderBy('pov.product_option_value_id')
            ->get(['pov.product_option_value_id', 'pov.option_id', 'pov.sku', 'pov.absolute_price', 'od.name as option_name']);

        $basePrice = (float) ($product->price ?? 0);
        $fixed = $listing->markup_fixed;
        $percent = $listing->markup_percent;
        if ($fixed === null && $percent === null && $group) {
            $fixed = $group['markup_fixed'] ?? null;
            $percent = $group['markup_percent'] ?? null;
        }
        $priceOf = fn (?float $absolute) => LazadaPushPayload::computeFinalPrice($absolute !== null ? $absolute : $basePrice, $fixed !== null ? (float) $fixed : null, $percent !== null ? (float) $percent : null);
        $prices = [];

        if ($povRows->count() > 0) {
            $optionIds = $povRows->pluck('option_id')->filter()->unique()->values();
            $combos = DB::table('product_option_combinations')->where('product_id', $productId)->orderBy('sort_order')->get(['id', 'sku', 'absolute_price']);

            if ($combos->isEmpty() && $optionIds->count() > 1) {
                $gaps[] = ['code' => ListingState::GAP_VARIATIONS, 'label' => 'its variation combinations stored (re-save the product to build them)'];
            } elseif ($combos->isNotEmpty()) {
                $axes = DB::table('product_option_combination_values as pocv')
                    ->join($pfx . 'product_option_value as pov2', 'pocv.product_option_value_id', '=', 'pov2.product_option_value_id')
                    ->whereIn('pocv.combination_id', $combos->pluck('id')->all())
                    ->distinct()->count('pov2.option_id');
                $keys = $template && $listing->primary_category_id
                    ? app(LazadaAttributes::class)->cachedSalePropKeys((int) $listing->primary_category_id)
                    : [];
                $keys = array_values(array_filter(array_unique($keys), fn ($k) => trim((string) $k) !== ''));
                if ($template && count($keys) < $axes) {
                    $gaps[] = ['code' => ListingState::GAP_VARIATIONS, 'label' => 'a Lazada category that allows ' . $axes . ' variation ' . Str::plural('axis', $axes) . ' (this one allows ' . count($keys) . ')'];
                }
                $blank = $combos->filter(fn ($c) => trim((string) ($c->sku ?? '')) === '')->count();
                if ($blank > 0) {
                    $gaps[] = ['code' => ListingState::GAP_VARIATIONS, 'label' => 'an SKU on every variation (' . $blank . ' without one)'];
                }
                foreach ($combos as $c) {
                    $prices[] = $priceOf($c->absolute_price !== null ? (float) $c->absolute_price : null);
                }
            } else {
                $overrides = LazadaProductVariant::query()->where('lazada_product_id', $listing->id)
                    ->whereNotNull('product_option_value_id')->pluck('seller_sku', 'product_option_value_id');
                $blank = $povRows->filter(function ($r) use ($overrides) {
                    $own = trim((string) ($overrides[(string) $r->product_option_value_id] ?? $overrides[(int) $r->product_option_value_id] ?? ''));

                    return $own === '' && trim((string) ($r->sku ?? '')) === '';
                })->count();
                if ($blank > 0) {
                    $gaps[] = ['code' => ListingState::GAP_VARIATIONS, 'label' => 'an SKU on every variation (' . $blank . ' without one)'];
                }
                foreach ($povRows as $r) {
                    $prices[] = $priceOf($r->absolute_price !== null ? (float) $r->absolute_price : null);
                }
            }
        } else {
            $prices[] = $priceOf(null);
        }

        $needsPrice = in_array('price', array_map(fn ($k) => strtolower((string) $k), $requiredKeys), true);
        if ($needsPrice && array_filter($prices, fn ($p) => $p <= 0) !== []) {
            $gaps[] = ['code' => ListingState::GAP_PRICE, 'label' => 'a price above zero on the catalog product and every variation'];
        }

        return $gaps;
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
        $categoryLabel = LazadaCategory::query()->exists() ? 'a Lazada category' : 'a Lazada category - none have been fetched for this store yet (fetch them on Catalog > Categories)';
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === []) {
            return [];
        }

        $catalog = CatalogGaps::forProducts($productIds, [CatalogGaps::ENABLED, CatalogGaps::SKU, CatalogGaps::IMAGE]);

        $out = [];
        foreach ($productIds as $productId) {
            $gaps = array_merge([['code' => ListingState::GAP_CATEGORY, 'label' => $categoryLabel]], $catalog[$productId] ?? []);
            $out[$productId] = ['ready' => false, 'missing' => array_map(fn ($g) => $g['label'], $gaps), 'gaps' => $gaps];
        }

        return $out;
    }
}
