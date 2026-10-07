<?php

namespace Extensions\lazada\Services\Lazada;

use Extensions\lazada\Models\LazadaBrand;
use Extensions\lazada\Models\LazadaProduct;
use Extensions\lazada\Models\LazadaCategoryTemplate;
use Extensions\lazada\Models\LazadaProductAttribute;
use Extensions\lazada\Models\LazadaProductVariant;
use Extensions\lazada\Models\LazadaSetting;
use Illuminate\Support\Facades\DB;

class LazadaPushPayload
{
    public static function computeFinalPrice(float $basePrice, ?float $fixedMarkup, ?float $percentMarkup): float
    {
        $price = $basePrice;
        if ($percentMarkup !== null && $percentMarkup > 0) {
            $price += $basePrice * $percentMarkup / 100;
        }
        if ($fixedMarkup !== null && $fixedMarkup > 0) {
            $price += $fixedMarkup;
        }
        return round($price, 2);
    }

    public static function startingPrice(?LazadaProduct $listing, float $catalogPrice): float
    {
        $own = $listing?->price;

        return ($own !== null && (float) $own > 0) ? (float) $own : $catalogPrice;
    }

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

        foreach (($overrides['attributes'] ?? ($inheritedGroup['attributes'] ?? [])) as $oKey => $oValue) {
            $oKey = (string) $oKey;
            if (!array_key_exists($oKey, $attrs) || trim((string) (is_array($attrs[$oKey]) ? implode('', $attrs[$oKey]) : $attrs[$oKey])) === '') {
                $attrs[$oKey] = $oValue;
            }
        }

        unset($attrs['__images__']);

        $attrs = (array) $this->compactPayloadValue($attrs);

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

            $attrs['brand'] = $brandName ? (string)$brandName : (string)$brandId;
            $attrs['brand_id'] = $brandId;
        } else {
            $attrs['brand'] = 'No Brand';
            unset($attrs['brand_id']);
        }

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

        $attrs = app(LazadaAttributes::class)->resolveMapAttributes($attrs, $product);

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

        $effectiveFixed = $listing->markup_fixed;
        $effectivePercent = $listing->markup_percent;
        if ($effectiveFixed === null && $effectivePercent === null) {
            $mkGroup = $listing->groups()->first();
            if ($mkGroup) {
                $effectiveFixed = $mkGroup->markup_fixed;
                $effectivePercent = $mkGroup->markup_percent;
            }
        }

        $variantKeys = [];
        $keyForPosition = [];
        $namePairs = [];
        $liveItem = null;
        $soldHidden = [];
        $combos = collect();
        $comboValuesByCombo = collect();
        if ($povRows->count() > 0) {
            $optionIds = $povRows->pluck('option_id')->filter()->unique()->values();

            $combos = DB::table('product_option_combinations as poc')
                ->where('poc.product_id', (int)$listing->product_id)
                ->orderBy('poc.sort_order')
                ->get();

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
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'variants' => 'This ERP product has multiple option groups but no combinations stored. Re-save the product to populate combinations, then retry.'
                ]);
            }

            $optionNamesOrdered = $combos->isNotEmpty()
                ? ($comboValuesByCombo->first() ?? collect())->pluck('option_name')->filter()->values()
                : $povRows->pluck('option_name')->filter()->unique()->values();

            // Never invent sale-prop keys: Lazada ignores unknown ones and rejects duplicate SKUs.
            // An item already on Lazada keeps its own keys, or an update adds a second variation type.
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

                $comboImg = trim((string)($c->image ?? ''));
                if ($comboImg !== '') {
                    $comboImg = \App\Services\Media\ImageCache::path($comboImg, \App\Services\Media\ImageCache::PUSH) ?? $comboImg;
                }
                if ($comboImg !== '') {
                    $comboUrl = app(LazadaImages::class)->normalizeImageUrl(\App\Services\Media\ImageCache::publicUrl($comboImg));
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
                if ($candidate !== null && $candidate !== '') {
                    if (isset($usedSellerSkus[$candidate])) {
                        $candidate = $candidate . '-' . (int)$r->product_option_value_id;
                    }
                    $usedSellerSkus[$candidate] = true;
                }

                $erpPrice = (float)($r->absolute_price ?? $basePrice);
                $finalPrice = $m && $m->price !== null ? (float)$m->price : (float)$erpPrice;
                $finalPrice = self::computeFinalPrice($finalPrice, $effectiveFixed, $effectivePercent);
                $skuRow = [
                    'SellerSku' => $candidate,
                    'price' => $finalPrice,
                    'quantity' => (int)$r->quantity,
                ];

                $optVal = trim((string)($r->option_value_name ?? ''));
                if ($optVal !== '' && !empty($variantKeys)) {
                    $skuRow[$variantKeys[0]] = $optVal;
                }

                $skus[] = $skuRow;
            }
        } else {
            $singlePrice = self::computeFinalPrice(self::startingPrice($listing, $basePrice), $effectiveFixed, $effectivePercent);
            $skus[] = [
                'SellerSku' => $fallbackBaseSku,
                'price' => $singlePrice,
                'quantity' => (int)($product->quantity ?? 0),
            ];
        }

        $skuDefaults = [];
        foreach (array_keys($attrs) as $k) {
            if (app(LazadaAttributes::class)->isSkuLevelRequiredKey($k)) {
                $val = $attrs[$k];
                if ($val !== null && trim((string)$val) !== '') {
                    $skuDefaults[$k] = $val;
                }
                unset($attrs[$k]);
            }
        }

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

        if ($liveItem !== null && $this->livePackagesDiffer($liveItem['packages'])) {
            foreach ($skus as $i => $row) {
                foreach (LazadaLiveListing::PACKAGE_FIELDS as $field) {
                    unset($skus[$i][$field]);
                }
            }
        }

        $attrs = app(LazadaAttributes::class)->autoFillBasics($attrs, $product);

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

        $attrFinal = is_array($attrs) ? $attrs : [];

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

        if (!empty($imageUrls)) {
            foreach ($payload['Request']['Product']['Skus']['Sku'] as $idx => $row) {
                if (!isset($payload['Request']['Product']['Skus']['Sku'][$idx]['Images'])) {
                    $payload['Request']['Product']['Skus']['Sku'][$idx]['Images'] = ['Image' => [$imageUrls[0]]];
                }
            }
        }

        $rename = $liveItem !== null
            ? $this->variationRename($namePairs, $liveItem, (int) $listing->primary_category_id)
            : ['variation' => [], 'renames' => [], 'skipped' => []];
        if ($rename['variation'] !== []) {
            $payload['Request']['Product']['variation'] = $rename['variation'];
        }

        $switch = LazadaVariationSwitch::plan((int) $listing->product_id, $soldHidden, $liveItem, $payload['Request']['Product']['Skus']['Sku']);
        $payload['Request']['Product']['Skus']['Sku'] = $switch['skus'];

        $preview = [
            'listing_id' => $listing->id,
            'primary_category_id' => $listing->primary_category_id,
            'attributes' => $attrs,
            'skus' => $skus,
            'images' => $imageUrls,
            'payload' => $payload,
            'variation_renames' => $rename['renames'],
            'variation_rename_skipped' => $rename['skipped'],
            'variations_off' => $switch['off'],
            'variations_back' => $switch['back'],
        ];

        return [$payload, $preview];
    }

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

    private static function nameKey(string $name): string
    {
        return mb_strtolower((string) preg_replace('/[\s_\-]+/u', '', $name));
    }

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

    public function renameWarning(array $preview): ?string
    {
        $skipped = (array) ($preview['variation_rename_skipped'] ?? []);

        return $skipped === [] ? null : 'Variation names were not changed: ' . implode('; ', $skipped) . '.';
    }

    private const STANDARD_SALE_PROPS = ['color_family', 'size'];

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

    public function buildPriceUpdateXml(int $skuId, string $price, ?string $salePrice = null, ?string $saleStart = null, ?string $saleEnd = null): string
    {
        $price = htmlspecialchars($price, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $sale = '';
        if ($salePrice !== null) {
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
        'field_errors' => $ok ? [] : $fieldErrors,
    ];
}

public function extractCreatedItemId(array $result): ?string
{
    $body = $result['body'] ?? null;
    if (!is_array($body)) {
        return null;
    }

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
