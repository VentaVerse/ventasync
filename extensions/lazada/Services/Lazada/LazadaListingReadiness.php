<?php

namespace Extensions\lazada\Services\Lazada;

use App\Integrations\Listings\CatalogGaps;
use App\Integrations\Listings\ListingState;
use Extensions\lazada\Models\LazadaCategory;
use Extensions\lazada\Models\LazadaCategoryTemplate;
use Extensions\lazada\Models\LazadaProductAttribute;
use Extensions\lazada\Models\LazadaSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LazadaListingReadiness
{
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

        $inherit = app(LazadaInheritedSettings::class);
        $productIds = $listings->pluck('product_id')->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();
        $groups = $inherit->forProducts($productIds);
        $listings = $listings->map(fn ($l) => $inherit->fill($l, $groups[(int) $l->product_id] ?? null)['listing']);
        if (!empty($overrides['settings'])) {
            $s = $overrides['settings'];
            $listings = $listings->map(function ($l) use ($s) {
                $c = clone $l;
                if ((int) ($c->primary_category_id ?? 0) <= 0 && !empty($s['primary_category_id'])) {
                    $c->primary_category_id = $s['primary_category_id'];
                }
                if ($c->markup_fixed === null && $c->markup_percent === null) {
                    $c->markup_fixed = $s['markup_fixed'] ?? null;
                    $c->markup_percent = $s['markup_percent'] ?? null;
                }

                return $c;
            });
        }
        $sharedAnswers = array_filter((array) ($overrides['attributes'] ?? []), fn ($v) => trim((string) $v) !== '');

        $products = DB::table($pfx . 'product as p')
            ->leftJoin($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')->where('pd.language_id', '=', $langId);
            })
            ->whereIn('p.product_id', $productIds)
            ->get(['p.product_id', 'p.sku', 'p.model', 'p.image', 'p.price', 'pd.name', 'pd.description'])
            ->keyBy('product_id');
        $ownPictures = $listings->filter(fn ($l) => ! empty($l->image_order))->pluck('product_id')
            ->map(fn ($v) => (int) $v)->all();
        $catalog = CatalogGaps::forProducts($productIds, [CatalogGaps::ENABLED, CatalogGaps::SKU, CatalogGaps::IMAGE], $ownPictures);

        $templates = LazadaCategoryTemplate::query()
            ->where('region', $region)
            ->whereIn('primary_category_id', $listings->pluck('primary_category_id')->filter()->unique()->all() ?: [0])
            ->get()
            ->keyBy('primary_category_id');

        $savedAttrs = LazadaProductAttribute::query()
            ->whereIn('lazada_product_id', $listings->pluck('id')->all())
            ->get()
            ->groupBy('lazada_product_id');

        $povByProduct = DB::table($pfx . 'product_option_value as pov')
            ->leftJoin($pfx . 'option_description as od', function ($j) use ($langId) {
                $j->on('pov.option_id', '=', 'od.option_id')->where('od.language_id', '=', $langId);
            })
            ->whereIn('pov.product_id', $productIds)
            ->orderBy('pov.product_option_value_id')
            ->get(['pov.product_id', 'pov.product_option_value_id', 'pov.option_id', 'pov.sku', 'pov.absolute_price', 'od.name as option_name'])
            ->groupBy('product_id');
        $combosByProduct = DB::table('product_option_combinations')
            ->whereIn('product_id', $productIds)
            ->orderBy('sort_order')
            ->get(['id', 'product_id', 'sku', 'absolute_price'])
            ->groupBy('product_id');
        $axisByProduct = [];
        $allComboIds = $combosByProduct->collapse()->pluck('id')->all();
        if ($allComboIds !== []) {
            foreach (DB::table('product_option_combination_values as pocv')
                ->join($pfx . 'product_option_value as pov2', 'pocv.product_option_value_id', '=', 'pov2.product_option_value_id')
                ->join('product_option_combinations as c', 'pocv.combination_id', '=', 'c.id')
                ->whereIn('pocv.combination_id', $allComboIds)
                ->get(['c.product_id', 'pov2.option_id'])
                ->groupBy('product_id') as $pid => $rows) {
                $axisByProduct[(int) $pid] = $rows->pluck('option_id')->unique()->count();
            }
        }

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

            $requiredKeys = [];
            $template = $listing->primary_category_id ? $templates->get((int) $listing->primary_category_id) : null;
            if ($listing->primary_category_id) {
                if (!$template || !$template->template_body) {
                    $gaps[] = ['code' => ListingState::GAP_SHEET, 'label' => "its category's attribute sheet (Refresh reads it)"];
                } else {
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
                            continue;
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
                            'keys' => $unansweredKeys];
                    }
                }
            }

            foreach ($this->variationGaps($listing, $product, $group, $template, $requiredKeys, $povByProduct->get($productId) ?? collect(), $combosByProduct->get($productId) ?? collect(), $axisByProduct[$productId] ?? 0) as $gap) {
                $gaps[] = $gap;
            }

            $missing = array_map(fn ($g) => $g['label'], $gaps);
            $out[(int) $listing->id] = ['ready' => empty($gaps), 'missing' => $missing, 'gaps' => $gaps];
        }

        return $out;
    }

    private function variationGaps($listing, ?object $product, ?array $group, ?LazadaCategoryTemplate $template, array $requiredKeys, $povRows, $combos, int $axes): array
    {
        $gaps = [];

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

            if ($combos->isEmpty() && $optionIds->count() > 1) {
                $gaps[] = ['code' => ListingState::GAP_VARIATIONS, 'label' => 'its variation combinations stored (re-save the product to build them)'];
            } elseif ($combos->isNotEmpty()) {
                $keys = $template && $listing->primary_category_id
                    ? app(LazadaAttributes::class)->cachedSalePropKeys((int) $listing->primary_category_id)
                    : [];
                $keys = array_values(array_filter(array_unique($keys), fn ($k) => trim((string) $k) !== ''));
                $onLazada = trim((string) ($listing->lazada_item_id ?? '')) !== '' && empty($listing->lazada_deleted_at);
                if ($template && ! $onLazada && count($keys) < $axes) {
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
                $blank = $povRows->filter(fn ($r) => trim((string) ($r->sku ?? '')) === '')->count();
                if ($blank > 0) {
                    $gaps[] = ['code' => ListingState::GAP_VARIATIONS, 'label' => 'an SKU on every variation (' . $blank . ' without one)'];
                }
                foreach ($povRows as $r) {
                    $prices[] = $priceOf($r->absolute_price !== null ? (float) $r->absolute_price : null);
                }
            }
        } else {
            $prices[] = $priceOf(LazadaPushPayload::startingPrice($listing, $basePrice));
        }

        $needsPrice = in_array('price', array_map(fn ($k) => strtolower((string) $k), $requiredKeys), true);
        if ($needsPrice && array_filter($prices, fn ($p) => $p <= 0) !== []) {
            $gaps[] = ['code' => ListingState::GAP_PRICE, 'label' => 'a price above zero on the catalog product and every variation'];
        }

        return $gaps;
    }

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
