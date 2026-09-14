<?php

namespace Extensions\lazada\Services\Lazada;

use Extensions\lazada\Models\LazadaBrand;
use Extensions\lazada\Models\LazadaProduct;
use Extensions\lazada\Models\LazadaCategoryTemplate;
use Extensions\lazada\Models\LazadaProductAttribute;
use Extensions\lazada\Models\LazadaProductVariant;
use Extensions\lazada\Models\LazadaSetting;
use Illuminate\Support\Facades\DB;

/**
 * What actually goes over the wire, out of the god controller (Phase C,
 * #184): the create/update payload built from the catalogue + the saved
 * sheet, the stock and price XML, the price formula, and the reading of
 * Lazada's answers. The seed of the one-pipeline-per-channel work in
 * Phase F - when PushPlan arrives, it grows here, not in a controller.
 *
 * Two dead XML builders (buildStockSellableUpdateXml, buildRemoveProductXml)
 * had no caller anywhere and did not make the move.
 */
class LazadaPushPayload
{
    public static function computeFinalPrice(float $basePrice, ?float $fixedMarkup, ?float $percentMarkup): float
    {
        // Formula: (Price * %) + Fixed
        $price = $basePrice;
        if ($percentMarkup !== null && $percentMarkup > 0) {
            $price += $basePrice * $percentMarkup / 100;
        }
        if ($fixedMarkup !== null && $fixedMarkup > 0) {
            $price += $fixedMarkup;
        }
        return round($price, 2);
    }

    /**
     * The price a product without variations starts from before the price
     * rule is added: the listing's own when set, else the catalog's. A
     * product with variations prices each variation instead, so callers ask
     * this only for a product that has none.
     */
    public static function startingPrice(?LazadaProduct $listing, float $catalogPrice): float
    {
        $own = $listing?->price;

        return ($own !== null && (float) $own > 0) ? (float) $own : $catalogPrice;
    }

    /**
     * The parcel a push sends, as Lazada's package_* SKU fields: the
     * listing's own weight and size, each blank one left out so the next
     * source fills it.
     *
     * @return array<string, string>
     */
    public static function ownParcel(LazadaProduct $listing): array
    {
        $out = [];
        foreach (['package_weight' => 'weight', 'package_length' => 'package_length', 'package_width' => 'package_width', 'package_height' => 'package_height'] as $field => $column) {
            $value = $listing->{$column} ?? null;
            if ($value !== null && (float) $value > 0) {
                $out[$field] = (string) $value;
            }
        }

        return $out;
    }

    public function buildLazadaProductCreatePayload(LazadaProduct $listing, ?object $setting = null, ?LazadaClient $client = null, array $overrides = []): array
    {
        /* The group's values fill only what the listing leaves blank, on
         * every push, in memory on a clone; the listing's own value always
         * wins and nothing is written anywhere. */
        $listing = clone $listing;
        $settings = (array) ($overrides['settings'] ?? []);
        if ((int) ($listing->primary_category_id ?? 0) <= 0 && !empty($settings['primary_category_id'])) {
            $listing->primary_category_id = $settings['primary_category_id'];
        }
        if ($listing->markup_fixed === null && $listing->markup_percent === null && (array_key_exists('markup_fixed', $settings) || array_key_exists('markup_percent', $settings))) {
            $listing->markup_fixed = $settings['markup_fixed'] ?? null;
            $listing->markup_percent = $settings['markup_percent'] ?? null;
        }
        $inheritedGroup = app(LazadaInheritedSettings::class)->forProducts([(int) $listing->product_id])[(int) $listing->product_id] ?? null;
        $listing = app(LazadaInheritedSettings::class)->fill($listing, $inheritedGroup)['listing'];

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $product = DB::table($pfx.'product as p')
            ->leftJoin($pfx.'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                    ->where('pd.language_id', '=', $langId);
            })
            ->leftJoin($pfx.'manufacturer as m', 'p.manufacturer_id', '=', 'm.manufacturer_id')
            ->where('p.product_id', (int)$listing->product_id)
            ->first([
                'p.product_id','p.sku','p.model','p.image','p.price','p.quantity',
                'p.weight','p.length','p.width','p.height','p.date_modified',
                'p.upc','p.ean','p.jan','p.isbn','p.mpn',
                'pd.name','pd.description','pd.meta_title','pd.meta_description',
                'm.name as manufacturer_name',
            ]);

        /* The listing's own words win over the catalog's, the way every
         * other channel's Content card works (2026-09-07). An empty column
         * follows the catalog, which is what every row starts as. The clone
         * keeps the read-only product row untouched for everything else. */
        if ($product) {
            $content = \App\Integrations\Listings\ListingContent::of(
                $listing,
                (string) ($product->name ?? ''),
                (string) ($product->description ?? ''),
                'lazada',
                (int) ($listing->lazada_setting_id ?? 0)
            );
            $product = clone $product;
            $product->name = $content['title'];
            $product->description = $content['description'];
        }

        // Images are mandatory for Lazada product creation.
        // Lazada checks Product.Images.Image (and sometimes Sku.Images.Image) for main image.
        $imageUrls = app(LazadaImages::class)->getProductImageUrls((int) $listing->product_id, $product, $listing->image_order ?? null, $listing);
        $imageUrls = array_values(array_filter(array_map(fn($u) => app(LazadaImages::class)->normalizeImageUrl((string)$u), $imageUrls)));
        if (empty($imageUrls)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'images' => 'At least 1 product image is required. Please upload a main image (and optional additional images) in the core ERP Product page first.'
            ]);
        }

        $attrs = LazadaProductAttribute::query()
            ->where('lazada_product_id', $listing->id)
            ->pluck('value', 'attribute_key')
            ->toArray();

        // The group's answers fill the attributes the listing leaves unanswered.
        foreach (($overrides['attributes'] ?? ($inheritedGroup['attributes'] ?? [])) as $oKey => $oValue) {
            $oKey = (string) $oKey;
            if (!array_key_exists($oKey, $attrs) || trim((string) (is_array($attrs[$oKey]) ? implode('', $attrs[$oKey]) : $attrs[$oKey])) === '') {
                $attrs[$oKey] = $oValue;
            }
        }

        // Internal/legacy key - do not send to Lazada as it does not satisfy QC.
        unset($attrs['__images__']);

        // Remove present-but-null values early; Lazada treats them as blank (C004).
        $attrs = (array) $this->compactPayloadValue($attrs);

        // Ensure Brand attributes are present.
        // Lazada examples show using brand name ("No Brand") and/or brand_id for registered brands.
        // We keep this logic defensive for sandbox testing where brand libraries may be incomplete.
        $brandOverride = trim((string)($listing->brand_name_override ?? ''));
        $brandId = (int)($listing->brand_id ?? 0);

        $isNoBrand = $brandOverride !== '' && strtolower($brandOverride) === 'no brand';

        if ($isNoBrand) {
            $attrs['brand'] = 'No Brand';
            unset($attrs['brand_id']);
        } elseif ($brandId > 0) {
            $brandName = LazadaBrand::query()
                ->where('region', $this->region())
                ->where('brand_id', $brandId)
                ->value('name');

            // Provide both brand (name) + brand_id to satisfy "brand is mandatory" checks while remaining compatible.
            $attrs['brand'] = $brandName ? (string)$brandName : (string)$brandId;
            $attrs['brand_id'] = $brandId;
        } else {
            // For payload preview and smoother sandbox testing, default to Lazada's "No Brand"
            // when no brand_id is selected and the No-Brand marker isn't set.
            // Lazada commonly treats "No Brand" as a valid brand value in Seller Center.
            $attrs['brand'] = 'No Brand';
            unset($attrs['brand_id']);
        }

        // Base SKU row (no variants) OR per-option-value rows.
        // We also pull option/value names so we can map them to Lazada variation attributes.
        $povRows = DB::table($pfx.'product_option_value as pov')
            ->leftJoin($pfx.'option_description as od', function ($j) use ($langId) {
                $j->on('pov.option_id', '=', 'od.option_id')
                    ->where('od.language_id', '=', $langId);
            })
            ->leftJoin($pfx.'option_value_description as ovd', function ($j) use ($langId) {
                $j->on('pov.option_value_id', '=', 'ovd.option_value_id')
                    ->where('ovd.language_id', '=', $langId);
            })
            ->where('pov.product_id', (int)$listing->product_id)
            ->orderBy('pov.product_option_value_id')
            ->get([
                'pov.product_option_value_id',
                'pov.option_id',
                'pov.option_value_id',
                'pov.sku',
                'pov.quantity',
                'pov.absolute_price',
                'od.name as option_name',
                'ovd.name as option_value_name',
            ]);

        $map = LazadaProductVariant::query()
            ->where('lazada_product_id', $listing->id)
            ->get()
            ->keyBy(function ($v) {
                return $v->product_option_value_id === null ? 'base' : (string)$v->product_option_value_id;
            });

        // Resolve __map: attribute values to actual product field values.
        $attrs = app(LazadaAttributes::class)->resolveMapAttributes($attrs, $product);

        // SellerSku fallback: sku then model.
        $fallbackBaseSku = null;
        if ($product) {
            $fallbackBaseSku = trim((string)($product->sku ?? ''));
            if ($fallbackBaseSku === '') {
                $fallbackBaseSku = trim((string)($product->model ?? ''));
            }
            if ($fallbackBaseSku === '') {
                $fallbackBaseSku = null;
            }
        }

        $skus = [];
        $basePrice = (float)($product->price ?? 0);
        $usedSellerSkus = [];

        // Effective markup for variant price computation
        $effectiveFixed = $listing->markup_fixed;
        $effectivePercent = $listing->markup_percent;
        if ($effectiveFixed === null && $effectivePercent === null) {
            $mkGroup = $listing->groups()->first();
            if ($mkGroup) {
                $effectiveFixed = $mkGroup->markup_fixed;
                $effectivePercent = $mkGroup->markup_percent;
            }
        }

        // If we have ERP option rows, Lazada requires *variation attributes* (aka sale props) per SKU.
        // Otherwise, Lazada will reject multi-SKU products with CHK_SKU_PROPS_DUPLICATE.
        // Multi-option products are stored in product_option_combinations (each combo = one SKU).
        // Single-option products store variant data directly on product_option_value rows.
        // We map ERP option value(s) onto Lazada sale-prop keys for the category.
        $variantKeys = [];
        $keyForPosition = [];
        $namePairs = [];
        $liveItem = null;
        $combos = collect();
        $comboValuesByCombo = collect();
        if ($povRows->count() > 0) {
            $optionIds = $povRows->pluck('option_id')->filter()->unique()->values();

            $combos = DB::table('product_option_combinations as poc')
                ->where('poc.product_id', (int)$listing->product_id)
                ->orderBy('poc.sort_order')
                ->get();

            // Only the variations this store sells go up.
            $soldHidden = \App\Integrations\Listings\ListingVariations::hidden('lazada', (int) ($listing->lazada_setting_id ?? 0), [(int) $listing->product_id]);
            if ($combos->isNotEmpty()) {
                $combos = $combos->filter(fn ($c) => \App\Integrations\Listings\ListingVariations::allows($soldHidden, (int) $listing->product_id, $c->sku))->values();
                if ($combos->isEmpty()) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'variants' => \App\Integrations\Listings\ListingVariations::noneSoldMessage('Lazada'),
                    ]);
                }
            }

            if ($combos->isNotEmpty()) {
                $comboIds = $combos->pluck('id')->toArray();
                $comboValuesByCombo = DB::table('product_option_combination_values as pocv')
                    ->join($pfx . 'product_option_value as pov2', 'pocv.product_option_value_id', '=', 'pov2.product_option_value_id')
                    ->join($pfx . 'option_description as od', function ($j) use ($langId) {
                        $j->on('pov2.option_id', '=', 'od.option_id')
                            ->where('od.language_id', '=', $langId);
                    })
                    ->join($pfx . 'option_value_description as ovd', function ($j) use ($langId) {
                        $j->on('pov2.option_value_id', '=', 'ovd.option_value_id')
                            ->where('ovd.language_id', '=', $langId);
                    })
                    ->whereIn('pocv.combination_id', $comboIds)
                    ->select(
                        'pocv.combination_id',
                        'pov2.option_id',
                        'od.name as option_name',
                        'ovd.name as value_name'
                    )
                    ->orderBy('pocv.combination_id')
                    ->orderBy('pov2.option_id')
                    ->get()
                    ->groupBy('combination_id');
            } elseif ($optionIds->count() > 1) {
                // Multi-option product without a combinations row — the saver should have populated them.
                // Bail rather than uploading wrong data.
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'variants' => 'This ERP product has multiple option groups but no combinations stored. Re-save the product to populate combinations, then retry.'
                ]);
            }

            $optionNamesOrdered = $combos->isNotEmpty()
                ? ($comboValuesByCombo->first() ?? collect())->pluck('option_name')->filter()->values()
                : $povRows->pluck('option_name')->filter()->unique()->values();

            // Resolve sale-prop keys, in priority order:
            //   1. Live API (/category/attributes/get) — most accurate
            //   2. Cached category template (lazada_category_templates) — survives rate limits
            //   3. Name-based guess for single-option products only
            //
            // We never invent extra keys beyond what the category actually exposes — Lazada
            // silently ignores unknown sale-prop keys, which collapses multi-SKU payloads
            // into duplicates and triggers CHK_SKU_PROPS_DUPLICATE.
            //
            // An item already on Lazada comes first, with the keys its own SKUs
            // carry: an update that sends any other key adds a second variation
            // type beside the item's own, so the item's keys always win there.
            $liveItem = $this->liveItemShape($listing, $setting, $client);
            $liveKeys = $liveItem['keys'] ?? null;
            if ($liveKeys !== null && $liveKeys !== []) {
                $variantKeys = $liveKeys;
            } else {
                if ($setting && $client && !empty($listing->primary_category_id)) {
                    $variantKeys = app(LazadaAttributes::class)->getLazadaSalePropKeys((int)$listing->primary_category_id, $setting, $client);
                }
                if (empty($variantKeys) && !empty($listing->primary_category_id)) {
                    $variantKeys = app(LazadaAttributes::class)->cachedSalePropKeys((int)$listing->primary_category_id);
                }
                if (empty($variantKeys) && !$combos->isNotEmpty()) {
                    $variantKeys = app(LazadaAttributes::class)->guessLazadaVariantKeysFromOptionName((string)($povRows->first()->option_name ?? ''));
                }
            }
            $variantKeys = array_values(array_filter(array_unique($variantKeys), fn ($k) => trim((string)$k) !== ''));

            if ($combos->isNotEmpty() && count($variantKeys) < $optionNamesOrdered->count()) {
                $allowed = count($variantKeys);
                $have = $optionNamesOrdered->count();
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'variants' => $liveKeys
                        ? "This item on Lazada has {$allowed} variation " . \Illuminate\Support\Str::plural('type', $allowed) . ' (' . implode(', ', $liveKeys) . "), but your product has {$have}. Remove a variation, or list the product as a new item."
                        : "Lazada won't accept this product. The selected Lazada category only allows {$allowed} variation per product, but your product has {$have}. Either remove a variation, or move this listing to a Lazada category that supports {$have}."
                ]);
            }

            // Which live key each catalog option rides, by position unless
            // the names say otherwise on an item with two or more types.
            if ($combos->isNotEmpty()) {
                $comboOptionNames = ($comboValuesByCombo->first() ?? collect())
                    ->map(fn ($v) => trim((string) ($v->option_name ?? '')))->values()->all();
                $keyForPosition = $liveKeys && count($liveKeys) >= 2
                    ? $this->pairOptionsWithLiveKeys($comboOptionNames, $liveKeys)
                    : $variantKeys;
                foreach ($comboOptionNames as $i => $optionName) {
                    if (isset($keyForPosition[$i])) {
                        $namePairs[] = [$optionName, $keyForPosition[$i]];
                    }
                }
            } elseif ($variantKeys !== []) {
                $namePairs[] = [trim((string) ($povRows->first()->option_name ?? '')), $variantKeys[0]];
            }
            if (!$combos->isNotEmpty() && $variantKeys === [] && $povRows->count() > 1) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'variants' => "Lazada needs a variation type for this product's options, and neither the item nor its Lazada category names one. Pick a Lazada category that has a variation property."
                ]);
            }
        }
        if ($combos->isNotEmpty()) {
            // Multi-option: each combination row is one Lazada SKU.
            // ERP options map to Lazada sale-prop slots by position (first ERP option
            // → first Lazada key, etc.) so the ERP variation order is preserved in
            // Lazada's UI. On an item already on Lazada with two or more types, an
            // option whose name matches a live key takes that key instead; see
            // pairOptionsWithLiveKeys().
            foreach ($combos as $c) {
                $values = $comboValuesByCombo->get($c->id) ?? collect();

                $candidate = trim((string)($c->sku ?? ''));
                if ($candidate === '') {
                    $valLabel = $values->pluck('value_name')->filter()->implode(' / ');
                    $human = $valLabel !== '' ? ('Variant: ' . $valLabel) : 'Variant';
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'variants.sku' => $human . ' SKU is required before uploading to Lazada.'
                    ]);
                }
                if (isset($usedSellerSkus[$candidate])) {
                    $candidate = $candidate . '-' . (int)$c->id;
                }
                $usedSellerSkus[$candidate] = true;

                $erpPrice = (float)($c->absolute_price ?? $basePrice);
                $finalPrice = self::computeFinalPrice($erpPrice, $effectiveFixed, $effectivePercent);
                $skuRow = [
                    'SellerSku' => $candidate,
                    'price' => $finalPrice,
                    'quantity' => (int)($c->quantity ?? 0),
                ];

                /* The variation's OWN picture rides its SKU (#137, Lazada
                 * half) - the image the product CRUD stores per
                 * combination, as a public URL. ensureLazadaInlinkImages
                 * already migrates SKU images to Lazada inlinks (or drops
                 * what cannot be fetched), so this only has to hand it the
                 * URL. */
                $comboImg = trim((string)($c->image ?? ''));
                if ($comboImg !== '') {
                    $comboImg = \App\Services\Media\StoreReadyImages::fit($comboImg, \App\Services\Media\StoreReadyImages::LAZADA) ?? $comboImg;
                }
                if ($comboImg !== '') {
                    $comboUrl = app(LazadaImages::class)->normalizeImageUrl(asset('storage/' . ltrim($comboImg, '/')));
                    if (is_string($comboUrl) && $comboUrl !== '') {
                        $skuRow['Images'] = ['Image' => [$comboUrl]];
                    }
                }

                foreach ($values->values() as $i => $v) {
                    if (!isset($keyForPosition[$i])) {
                        continue;
                    }
                    $valName = trim((string)($v->value_name ?? ''));
                    if ($valName !== '') {
                        $skuRow[$keyForPosition[$i]] = $valName;
                    }
                }

                $skus[] = $skuRow;
            }
        } elseif ($povRows->count() > 0) {
            $soldPov = $povRows->filter(fn ($r) => \App\Integrations\Listings\ListingVariations::allows($soldHidden, (int) $listing->product_id, $r->sku))->values();
            if ($soldPov->isEmpty()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'variants' => \App\Integrations\Listings\ListingVariations::noneSoldMessage('Lazada'),
                ]);
            }
            foreach ($soldPov as $r) {
                $key = (string)$r->product_option_value_id;
                $m = $map->get($key);
                // SKU and stock always come from the catalog; only the
                // listing's own price for a variation is an override.
                $candidate = trim((string)($r->sku ?? ''));
                if ($candidate === '') {
                    $label = trim((string)($r->option_name ?? ''));
                    $valLabel = trim((string)($r->option_value_name ?? ''));
                    $human = $label !== '' ? ($label . ($valLabel !== '' ? (': '.$valLabel) : '')) : 'Variant';
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'variants.sku' => $human . ' SKU is required before uploading to Lazada.'
                    ]);
                }
                if ($candidate === '') {
                    $candidate = $fallbackBaseSku;
                }
                // Ensure uniqueness across variant rows
                if ($candidate !== null && $candidate !== '') {
                    if (isset($usedSellerSkus[$candidate])) {
                        $candidate = $candidate . '-' . (int)$r->product_option_value_id;
                    }
                    $usedSellerSkus[$candidate] = true;
                }

                $erpPrice = (float)($r->absolute_price ?? $basePrice);
                $finalPrice = $m && $m->price !== null ? (float)$m->price : (float)$erpPrice;
                // Apply markup to variant price
                $finalPrice = self::computeFinalPrice($finalPrice, $effectiveFixed, $effectivePercent);
                $skuRow = [
                    'SellerSku' => $candidate,
                    'price' => $finalPrice,
                    'quantity' => (int)$r->quantity,
                ];

                // Variation attribute mapping (1 or 2 keys) so each SKU is distinguishable.
                // Lazada expects the *attribute key* as the XML/JSON key at SKU level (e.g., color_family, size, etc.).
                $optVal = trim((string)($r->option_value_name ?? ''));
                if ($optVal !== '' && !empty($variantKeys)) {
                    // Single option group -> we only need the first key.
                    $skuRow[$variantKeys[0]] = $optVal;
                }

                $skus[] = $skuRow;
            }
        } else {
            // No variants in ERP; create a single base SKU. The listing's own
            // price, else the catalog's, with the price rule added on top.
            $singlePrice = self::computeFinalPrice(self::startingPrice($listing, $basePrice), $effectiveFixed, $effectivePercent);
            $skus[] = [
                'SellerSku' => $fallbackBaseSku,
                'price' => $singlePrice,
                'quantity' => (int)($product->quantity ?? 0),
            ];
        }

        // Move SKU-level keys out of Attributes into each SKU row.
        // Lazada QC expects these at SKU level; sending them under Attributes can be treated as "blank".
        $skuDefaults = [];
        foreach (array_keys($attrs) as $k) {
            if (app(LazadaAttributes::class)->isSkuLevelRequiredKey($k)) {
                $val = $attrs[$k];
                // Keep only meaningful values.
                if ($val !== null && trim((string)$val) !== '') {
                    $skuDefaults[$k] = $val;
                }
                unset($attrs[$k]);
            }
        }

        // The listing's own parcel comes first, then an attribute answer,
        // then the catalog product's own weight and size.
        $skuDefaults = self::ownParcel($listing) + $skuDefaults;
        if (!array_key_exists('package_weight', $skuDefaults) && $product && $product->weight !== null) {
            $w = (string)$product->weight;
            if (trim($w) !== '') $skuDefaults['package_weight'] = $w;
        }
        if (!array_key_exists('package_length', $skuDefaults) && $product && $product->length !== null) {
            $v = (string)$product->length;
            if (trim($v) !== '') $skuDefaults['package_length'] = $v;
        }
        if (!array_key_exists('package_width', $skuDefaults) && $product && $product->width !== null) {
            $v = (string)$product->width;
            if (trim($v) !== '') $skuDefaults['package_width'] = $v;
        }
        if (!array_key_exists('package_height', $skuDefaults) && $product && $product->height !== null) {
            $v = (string)$product->height;
            if (trim($v) !== '') $skuDefaults['package_height'] = $v;
        }

        if (!empty($skuDefaults)) {
            foreach ($skus as $i => $row) {
                foreach ($skuDefaults as $k => $v) {
                    if (!array_key_exists($k, $row) || $row[$k] === null || trim((string)$row[$k]) === '') {
                        $row[$k] = $v;
                    }
                }
                $skus[$i] = $row;
            }
        }

        /* The catalog has one weight and one size per product. When the
         * item's variations already differ from one another on Lazada, an
         * update leaves the package fields off so each keeps its own. */
        if ($liveItem !== null && $this->livePackagesDiffer($liveItem['packages'])) {
            foreach ($skus as $i => $row) {
                foreach (LazadaLiveListing::PACKAGE_FIELDS as $field) {
                    unset($skus[$i][$field]);
                }
            }
        }

        // What the catalogue can answer, it answers, BEFORE the gate below:
        // this fallback used to sit after it, where it could never fire.
        $attrs = app(LazadaAttributes::class)->autoFillBasics($attrs, $product);

        // Local mandatory checks (attribute + sku level) before calling Lazada.
        $requiredKeys = [];
        $requiredNames = [];
        $template = LazadaCategoryTemplate::query()
            ->where('region', $this->region())
            ->where('primary_category_id', (int)$listing->primary_category_id)
            ->first();
        if ($template && $template->template_body) {
            $templateAttrs = app(LazadaAttributes::class)->extractAttributes($template->template_body);

            foreach ($templateAttrs as $a) {
                if (!empty($a['required'])) {
                    $k = (string)($a['key'] ?? '');
                    if ($k !== '') {
                        $requiredKeys[] = $k;
                        $requiredNames[$k] = (string)($a['name'] ?? $k);
                    }
                }
            }
        }

        $errs = [];
        foreach ($requiredKeys as $k) {
            if (app(LazadaAttributes::class)->isSkuLevelRequiredKey($k)) {
                continue;
            }
            $val = $attrs[$k] ?? null;
            if ($val === null || trim((string)$val) === '') {
                $label = $requiredNames[$k] ?? $k;
                $errs['attributes.'.$k] = $label . ' is mandatory.';
            }
        }
        if (!empty($errs)) {
            throw \Illuminate\Validation\ValidationException::withMessages($errs);
        }

        // SKU-level mandatory
        $needsSellerSku = false;
        $needsPrice = false;
        foreach ($requiredKeys as $k) {
            $lk = strtolower((string)$k);
            if ($lk === 'sellersku' || $lk === 'seller_sku') $needsSellerSku = true;
            if ($lk === 'price') $needsPrice = true;
        }
        if ($needsSellerSku) {
            foreach ($skus as $s) {
                if (empty($s['SellerSku'])) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'variants' => 'SellerSku is mandatory. Please set the SKU on the catalog product.'
                    ]);
                }
            }
        }
        if ($needsPrice) {
            foreach ($skus as $s) {
                if (!isset($s['price']) || (float)$s['price'] <= 0) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'variants' => 'Price is mandatory. Please set product price in ERP (base price) and/or option price adjustments, or fill Variant Mapping overrides.'
                    ]);
                }
            }
        }

        // Lazada payload (best-effort). We'll adjust based on QC responses.
        // IMPORTANT: Do NOT send present-but-null attributes; Lazada treats them as "blank" (C004).
        // (name/description used to be re-filled here, after the gate; that
        // now happens once in autoFillBasics(), before it.)
        $attrFinal = is_array($attrs) ? $attrs : [];

        // Clean null/blank values before sending to Lazada.
        $attrFinal = (array) $this->compactPayloadValue($attrFinal);

        $payload = [
            'Request' => [
                'Product' => [
                    'PrimaryCategory' => (int)$listing->primary_category_id,
                    'Images' => [
                        'Image' => $imageUrls,
                    ],
                    'Attributes' => $attrFinal,
                    'Skus' => [
                        'Sku' => $skus,
                    ],
                ],
            ],
        ];

        // Some categories validate the main image at SKU level. Apply the first image as the main SKU image.
        if (!empty($imageUrls)) {
            foreach ($payload['Request']['Product']['Skus']['Sku'] as $idx => $row) {
                if (!isset($payload['Request']['Product']['Skus']['Sku'][$idx]['Images'])) {
                    $payload['Request']['Product']['Skus']['Sku'][$idx]['Images'] = ['Image' => [$imageUrls[0]]];
                }
            }
        }

        /* A seller-defined variation type takes the catalog option's name.
         * The SKUs keep the item's live keys, so a Lazada that ignores the
         * variation object changes nothing; standard types are never sent. */
        $rename = $liveItem !== null
            ? $this->variationRename($namePairs, $liveItem, (int) $listing->primary_category_id)
            : ['variation' => [], 'renames' => [], 'skipped' => []];
        if ($rename['variation'] !== []) {
            $payload['Request']['Product']['variation'] = $rename['variation'];
        }

        $preview = [
            'listing_id' => $listing->id,
            'primary_category_id' => $listing->primary_category_id,
            'attributes' => $attrs,
            'skus' => $skus,
            'images' => $imageUrls,
            'payload' => $payload,
            'variation_renames' => $rename['renames'],
            'variation_rename_skipped' => $rename['skipped'],
        ];

        return [$payload, $preview];
    }

    /**
     * Remove null/blank values recursively from an array.
     *
     * Lazada treats present-but-null fields as "blank" and may fail QC (C004).
     * We only remove: null, empty strings, and empty arrays. We keep numeric 0 and string "0".
     *
     *  mixed $value
     *  mixed
     */
    /**
     * An item already on Lazada as its one item/get read describes it (see
     * LazadaLiveListing::shapeOf), or null when the listing is not on
     * Lazada or no store is at hand to ask. Lazada not answering refuses
     * the update: a guessed key would add a variation type the item does
     * not have.
     *
     * @return array{keys: list<string>, packages: list<array<string, string>>, variation: ?list<array{slot: int, name: string, customize: bool, has_image: mixed}>}|null
     */
    private function liveItemShape(LazadaProduct $listing, ?object $setting, ?LazadaClient $client): ?array
    {
        $itemId = trim((string) ($listing->lazada_item_id ?? ''));
        if ($itemId === '' || !empty($listing->lazada_deleted_at) || !$setting || !$client) {
            return null;
        }

        try {
            return (new LazadaLiveListing($client))->itemShape(
                $setting, \Extensions\lazada\Models\LazadaSetting::activeCredentials($setting), $itemId
            );
        } catch (\RuntimeException $e) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'variants' => 'Lazada did not say which variation types this item already has (' . $e->getMessage() . '), so nothing was sent. Try again.',
            ]);
        }
    }

    /** A variation name as compared: case, spaces, underscores and hyphens do not count. */
    private static function nameKey(string $name): string
    {
        return mb_strtolower((string) preg_replace('/[\s_\-]+/u', '', $name));
    }

    /**
     * Each catalog option, by its position in the combinations, paired with
     * the live key it rides. A name that matches a live key takes that key;
     * the rest go by position. When a name matches a key at another
     * position and another option matches no key at all, the names say the
     * orders differ without saying how, so the update is refused.
     *
     * @param  list<string>  $optionNames
     * @param  list<string>  $liveKeys
     * @return array<int, string>
     */
    private function pairOptionsWithLiveKeys(array $optionNames, array $liveKeys): array
    {
        $byName = [];
        $taken = [];
        foreach ($optionNames as $i => $name) {
            $want = self::nameKey($name);
            if ($want === '') {
                continue;
            }
            foreach ($liveKeys as $j => $key) {
                if (!isset($taken[$j]) && self::nameKey($key) === $want) {
                    $byName[$i] = $j;
                    $taken[$j] = true;
                    break;
                }
            }
        }

        $crossed = false;
        foreach ($byName as $i => $j) {
            if ($i !== $j) {
                $crossed = true;
            }
        }

        $pairs = [];
        foreach ($optionNames as $i => $name) {
            if (isset($byName[$i])) {
                $pairs[$i] = $liveKeys[$byName[$i]];
                continue;
            }
            if ($crossed || !isset($liveKeys[$i]) || isset($taken[$i])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'variants' => 'This item on Lazada has its variations in the order ' . implode(', ', $liveKeys)
                        . ' and your product has ' . implode(', ', array_map(fn ($n) => $n !== '' ? $n : '(unnamed)', $optionNames))
                        . '; the names do not say which is which, so nothing was sent.',
                ]);
            }
            $pairs[$i] = $liveKeys[$i];
            $taken[$i] = true;
        }

        return $pairs;
    }

    /**
     * True when the item's SKUs carry different values from one another in
     * any package field. Values compare as numbers when they are numbers,
     * so "10.00" and "10" are the same size.
     *
     * @param  list<array<string, string>>  $packages
     */
    private function livePackagesDiffer(array $packages): bool
    {
        foreach (LazadaLiveListing::PACKAGE_FIELDS as $field) {
            $seen = [];
            foreach ($packages as $package) {
                if (!isset($package[$field])) {
                    continue;
                }
                $value = $package[$field];
                $seen[is_numeric($value) ? sprintf('%.4F', (float) $value) : mb_strtolower($value)] = true;
            }
            if (count($seen) > 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The variation object an update carries to rename seller-defined types
     * to the catalog option's name, and the renames it asks for. A type is
     * renamed only when Lazada reports it customize=true, its name is not
     * one of the category's own sale-prop keys, and the catalog name differs
     * by more than case, spaces, underscores or hyphens and is not already
     * another type's name. Each renamed type goes in its own slot with
     * Lazada's own has_image and customize; nothing else is sent. A rename
     * that cannot be decided (no variation object in the item's answer, so
     * custom cannot be told from standard; the type missing from it; the
     * new name already taken) is skipped with its reason, and the update
     * goes ahead without it. A standard type is left alone without a reason.
     *
     * @param  list<array{0: string, 1: string}>  $namePairs  [catalog option name, live key]
     * @return array{variation: array<string, array<string, mixed>>, renames: list<array{from: string, to: string}>, skipped: list<string>}
     */
    private function variationRename(array $namePairs, array $liveItem, int $categoryId): array
    {
        $out = ['variation' => [], 'renames' => [], 'skipped' => []];
        if ($namePairs === []) {
            return $out;
        }
        $types = is_array($liveItem['variation'] ?? null) ? $liveItem['variation'] : [];

        $standard = [];
        foreach (array_merge(self::STANDARD_SALE_PROPS, app(LazadaAttributes::class)->cachedSalePropKeys($categoryId)) as $key) {
            $standard[self::nameKey((string) $key)] = true;
        }
        $liveNames = [];
        foreach (array_merge($liveItem['keys'] ?? [], array_column($types, 'name')) as $name) {
            $liveNames[self::nameKey((string) $name)] = true;
        }

        foreach ($namePairs as [$optionName, $liveKey]) {
            $optionName = trim((string) $optionName);
            $to = self::nameKey($optionName);
            $from = self::nameKey((string) $liveKey);
            if ($to === '' || $to === $from || isset($standard[$from])) {
                continue;
            }
            if ($types === []) {
                $out['skipped'][] = 'Lazada did not say which variation types on this item are your own';
                continue;
            }
            if (isset($liveNames[$to])) {
                $out['skipped'][] = $optionName . ' is already a variation type on this item';
                continue;
            }
            $type = null;
            foreach ($types as $candidate) {
                if (self::nameKey($candidate['name']) === $from) {
                    $type = $candidate;
                    break;
                }
            }
            if ($type === null) {
                $out['skipped'][] = 'Lazada did not describe the ' . $liveKey . ' type';
                continue;
            }
            if (!$type['customize']) {
                continue;
            }
            $entry = ['name' => $optionName];
            if ($type['has_image'] !== null) {
                $entry['has_image'] = $type['has_image'];
            }
            $entry['customize'] = true;
            $out['variation']['Variation' . $type['slot']] = $entry;
            $out['renames'][] = ['from' => $type['name'], 'to' => $optionName];
            $liveNames[$to] = true;
        }
        $out['skipped'] = array_values(array_unique($out['skipped']));

        return $out;
    }

    /** The warning a result message carries when a wanted rename was skipped, or null. */
    public function renameWarning(array $preview): ?string
    {
        $skipped = (array) ($preview['variation_rename_skipped'] ?? []);

        return $skipped === [] ? null : 'Variation names were not changed: ' . implode('; ', $skipped) . '.';
    }

    /** Lazada's own sale-prop keys, standard in every category that has them. */
    private const STANDARD_SALE_PROPS = ['color_family', 'size'];

    /**
     * After an update that asked for a rename, the item as Lazada now holds
     * it: when it shows both the old name and the new one, the line to
     * record. A rename Lazada ignored, one it took, or a read that did not
     * come back gives null.
     *
     * @param  list<array{from: string, to: string}>  $renames
     */
    public function renameReadBack(array $renames, ?array $itemData): ?string
    {
        if ($renames === [] || $itemData === null) {
            return null;
        }
        $shape = LazadaLiveListing::shapeOf($itemData);
        $names = [];
        foreach (array_merge($shape['keys'], array_column($shape['variation'] ?? [], 'name')) as $name) {
            $names[self::nameKey((string) $name)] = true;
        }

        $lines = [];
        foreach ($renames as $rename) {
            if (isset($names[self::nameKey($rename['from'])], $names[self::nameKey($rename['to'])])) {
                $lines[] = 'Lazada kept ' . $rename['from'] . ' and added ' . $rename['to'] . '.';
            }
        }

        return $lines === [] ? null : implode(' ', $lines) . ' Remove one in Seller Center.';
    }

    public function compactPayloadValue($value)
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $v2 = $this->compactPayloadValue($v);
                if (is_array($v2) && empty($v2)) {
                    continue;
                }
                if ($v2 === null) {
                    continue;
                }
                if (is_string($v2) && trim($v2) === '') {
                    continue;
                }
                $out[$k] = $v2;
            }
            return $out;
        }
        return $value;
    }

    /**
     * Build XML payload for /product/price_quantity/update (quantity only, using SkuId).
     */
    public function buildQuantityUpdateXml(int $skuId, int $quantity): string
    {
        $quantity = max(0, $quantity);

        return '<Request>'
            . '<Product><Skus><Sku>'
            . '<SkuId>' . $skuId . '</SkuId>'
            . '<Quantity>' . $quantity . '</Quantity>'
            . '</Sku></Skus></Product>'
            . '</Request>';
    }

    /**
     * Build XML payload for /product/price_quantity/update (price only, using SkuId).
     */
    public function buildPriceUpdateXml(int $skuId, string $price, ?string $salePrice = null, ?string $saleStart = null, ?string $saleEnd = null): string
    {
        $price = htmlspecialchars($price, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $sale = '';
        if ($salePrice !== null) {
            // SalePrice 0 clears a special price; with dates it schedules
            // one - Lazada's own "Edit Special Price" wire.
            $sale = '<SalePrice>' . htmlspecialchars($salePrice, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</SalePrice>';
            if ($saleStart !== null) {
                $sale .= '<SaleStartDate>' . htmlspecialchars($saleStart, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</SaleStartDate>';
            }
            if ($saleEnd !== null) {
                $sale .= '<SaleEndDate>' . htmlspecialchars($saleEnd, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</SaleEndDate>';
            }
        }

        return '<Request>'
            . '<Product><Skus><Sku>'
            . '<SkuId>' . $skuId . '</SkuId>'
            . '<Price>' . $price . '</Price>'
            . $sale
            . '</Sku></Skus></Product>'
            . '</Request>';
    }

/**
 * Lazada's verdict on a call, in words an operator can act on.
 *
 * Lazada answers HTTP 200 with the refusal in the body: a code (4108,
 * E501...), a broad message ("E500: Create product failed") and, when the
 * product failed validation, a detail[] list naming each field
 * ("p-100005514": "C004: cannot be blank"). The detail is the message
 * that matters (the owner, 2026-09-06), so it leads; the broad message stays
 * as the headline only when there is no detail. $fieldLabel turns a
 * field id into its attribute's name when the caller knows the category
 * template.
 *
 * @param  null|callable(string): ?string  $fieldLabel
 * @return array{ok: bool, code: ?string, message: ?string, details: list<string>}
 */
public function extractLazadaError(array $result, ?callable $fieldLabel = null): array
{
    $body = $result['body'] ?? null;
    $code = null;
    $message = null;
    $details = [];
    $fieldErrors = [];

    if (is_array($body)) {
        $code = $body['code'] ?? ($body['error_code'] ?? null);
        $message = $body['message'] ?? ($body['error_message'] ?? null);
        foreach ((array) ($body['detail'] ?? []) as $d) {
            if (! is_array($d)) {
                continue;
            }
            $text = trim((string) ($d['message'] ?? ''));
            if ($text === '') {
                continue;
            }
            $field = trim((string) ($d['field'] ?? ''));
            $label = $field !== '' ? ($fieldLabel ? $fieldLabel($field) : null) : null;
            $where = $label ?: $field;
            $details[] = $where !== '' ? $where . ': ' . $text : $text;
            $fieldErrors[] = ['field' => $field, 'message' => $text];
        }
    }

    if ($code === null && is_string($body)) {
        $message = trim($body);
    }

    $codeStr = $code !== null ? trim((string) $code) : null;
    $msgStr = $message !== null ? trim((string) $message) : null;

    $okHttp = (bool) ($result['ok'] ?? false);
    $okCode = ($codeStr === null) || ($codeStr === '0');
    $ok = $okHttp && $okCode;

    $said = $details !== [] ? implode('; ', $details) : ($msgStr ?: 'Unknown error');

    return [
        'ok' => $ok,
        'code' => $ok ? null : ($codeStr ?: 'UNKNOWN'),
        'message' => $ok ? null : $said,
        'details' => $details,
        // The raw field and text of each detail, for the sheet to put the
        // refusal on the row it names (the owner, 2026-09-06).
        'field_errors' => $ok ? [] : $fieldErrors,
    ];
}

/**
 * Best-effort extractor for Lazada created item_id from /product/create response.
 * Lazada responses vary by region/version; we try a few common shapes.
 */
public function extractCreatedItemId(array $result): ?string
{
    $body = $result['body'] ?? null;
    if (!is_array($body)) {
        return null;
    }

    // Common shapes:
    // {"data": {"item_id": "123"}}
    // {"data": {"item_id": 123}}
    // {"item_id": "123"}
    $candidates = [
        data_get($body, 'data.item_id'),
        data_get($body, 'data.itemId'),
        data_get($body, 'item_id'),
        data_get($body, 'itemId'),
        data_get($body, 'ItemId'),
    ];

    foreach ($candidates as $v) {
        if (is_numeric($v)) {
            return (string) $v;
        }
        if (is_string($v) && trim($v) !== '') {
            return trim($v);
        }
    }

    return null;
}

    /**
     * The one line the operator reads after a Lazada write. Success or
     * refusal by Lazada's own verdict, never the HTTP status ("HTTP 200 •
     * OK • 4108 - E500" read as a success to everyone who saw it). A
     * refusal leads with the detail Lazada gave, then its code.
     *
     * @param  null|callable(string): ?string  $fieldLabel  see extractLazadaError()
     */
    public function formatLazadaResultMessage(string $actionLabel, array $result, ?callable $fieldLabel = null): string
    {
        $e = $this->extractLazadaError($result, $fieldLabel);
        if ($e['ok']) {
            return $actionLabel . ' accepted by Lazada.';
        }
        $status = (int) ($result['status'] ?? 0);
        if ($status === 0) {
            return $actionLabel . ' did not reach Lazada: ' . ($e['message'] ?: 'no answer.');
        }

        return $actionLabel . ' refused by Lazada: ' . $e['message'] . ($e['code'] && $e['code'] !== 'UNKNOWN' ? ' (code ' . $e['code'] . ')' : '');
    }

    /**
     * For ERP products with options, return variant SellerSku + quantity from product_option_value.
     * If a row has empty sku, it is ignored (can't sync to Lazada without SellerSku).
     */
    public function getErpVariantStockByProductId(int $productId): array
    {
        $pfx = (string) config('catalog.prefix');

        $rows = DB::table($pfx.'product_option_value as pov')
            ->where('pov.product_id', $productId)
            ->whereNotNull('pov.sku')
            ->where('pov.sku', '!=', '')
            ->orderBy('pov.product_option_value_id')
            ->get(['pov.sku', 'pov.quantity']);

        $out = [];
        foreach ($rows as $r) {
            $sku = trim((string) ($r->sku ?? ''));
            if ($sku === '') {
                continue;
            }
            $out[] = [
                'seller_sku' => $sku,
                'quantity' => max(0, (int) ($r->quantity ?? 0)),
            ];
        }
        return $out;
    }

    /**
     * For ERP products with options, get variant prices from absolute_price column.
     */
    public function getErpVariantPricesByProductId(int $productId, float $basePrice): array
    {
        $pfx = (string) config('catalog.prefix');

        $rows = DB::table($pfx.'product_option_value as pov')
            ->where('pov.product_id', $productId)
            ->whereNotNull('pov.sku')
            ->where('pov.sku', '!=', '')
            ->orderBy('pov.product_option_value_id')
            ->get(['pov.sku', 'pov.absolute_price']);

        $out = [];
        foreach ($rows as $r) {
            $sku = trim((string) ($r->sku ?? ''));
            if ($sku === '') {
                continue;
            }

            $out[] = [
                'seller_sku' => $sku,
                'price' => max(0, (float) ($r->absolute_price ?? $basePrice)),
            ];
        }

        return $out;
    }

    private function region(): string
    {
        $setting = LazadaSetting::defaultStore();
        return (string)($setting->region ?? '');
    }
}
