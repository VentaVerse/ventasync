<?php

namespace Extensions\TikTok\Services\TikTok;

use Illuminate\Support\Facades\DB;

class TikTokVariationPush
{
    public const MAX_AXES = 3;

    /**
     * TikTok Shop reads a variation's picture from its first sales attribute and ignores one on the SKU.
     * The picture rides on the SKU while the payload is built and shaped, and moves to its place here, just before sending.
     */
    public static function withImages(array $skus): array
    {
        // The picture belongs to the first attribute's value, so every SKU sharing that value shows the same one.
        $byValue = [];
        foreach ($skus as $sku) {
            if (is_array($sku) && isset($sku['sku_img'], $sku['sales_attributes'][0])) {
                $byValue[self::firstValue($sku)] ??= $sku['sku_img'];
            }
        }
        foreach ($skus as $i => $sku) {
            if (! is_array($sku)) {
                continue;
            }
            unset($skus[$i]['sku_img']);
            $image = isset($sku['sales_attributes'][0]) && is_array($sku['sales_attributes'][0]) ? ($byValue[self::firstValue($sku)] ?? null) : null;
            if ($image !== null) {
                $skus[$i]['sales_attributes'][0]['sku_img'] = $image;
            }
        }

        return $skus;
    }

    private static function firstValue(array $sku): string
    {
        $first = (array) $sku['sales_attributes'][0];

        return mb_strtolower(trim((string) ($first['value_id'] ?? '') !== '' ? 'id:' . $first['value_id'] : 'name:' . ($first['value_name'] ?? '')));
    }

    public function skus(object $catalog, \Closure $priceFor, ?string $warehouseId, ?\Closure $uploadUri = null, ?array $existing = null): array
    {
        $langId = (int) config('catalog.default_language_id');
        $productId = (int) $catalog->product_id;
        $basePrice = (float) ($catalog->price ?? 0);
        $existing = $existing ?? [];

        $inventory = function (int $qty) use ($warehouseId): array {
            return [array_filter([
                'warehouse_id' => $warehouseId ?: null,
                'quantity' => max(0, $qty),
            ], fn ($v) => $v !== null)];
        };
        $money = fn (float $amount) => ['amount' => (string) round($amount), 'currency' => 'PHP'];

        $combos = DB::table('product_variations')
            ->where('product_id', $productId)
            ->orderBy('sort_order')
            ->get(['id', 'sku', 'quantity', 'absolute_price', 'image', 'status']);

        $hidden = \App\Channels\Listings\ListingVariations::hidden('tiktok', (int) (\Extensions\TikTok\Models\TikTokStore::defaultStore()?->id ?? 0), [$productId]);
        $own = \App\Channels\Listings\VariationPrices::forProducts('tiktok', (int) (\Extensions\TikTok\Models\TikTokStore::defaultStore()?->id ?? 0), [$productId]);
        if ($combos->isNotEmpty()) {
            $combos = $combos->filter(fn ($c) => \App\Channels\Listings\ListingVariations::allows($hidden, $productId, $c->sku))->values();
            if ($combos->isEmpty()) {
                return ['skus' => [], 'kind' => 'combos', 'keys' => [], 'refused' => \App\Channels\Listings\ListingVariations::noneSoldMessage('TikTok Shop')];
            }
        }

        if ($combos->isNotEmpty()) {
            $values = DB::table('product_variation_parts as cv')
                ->join('product_variation_values as pov', 'cv.product_variation_value_id', '=', 'pov.product_variation_value_id')
                ->join('variation_value_descriptions as ovd', function ($j) use ($langId) {
                    $j->on('pov.variation_value_id', '=', 'ovd.variation_value_id')->where('ovd.language_id', '=', $langId);
                })
                ->join('variation_type_descriptions as od', function ($j) use ($langId) {
                    $j->on('pov.variation_type_id', '=', 'od.variation_type_id')->where('od.language_id', '=', $langId);
                })
                ->whereIn('cv.product_variation_id', $combos->pluck('id')->all())
                ->orderBy('pov.product_variation_type_id')
                ->orderBy('pov.product_variation_value_id')
                ->get(['cv.product_variation_id', 'pov.product_variation_type_id', 'ovd.name as value_name', 'od.name as variation_type_name']);

            $axes = [];
            $comboAxes = [];
            foreach ($values as $v) {
                $axisId = (int) $v->product_variation_type_id;
                $axes[$axisId] ??= html_entity_decode((string) $v->variation_type_name, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?: 'Variation';
                $comboAxes[(int) $v->product_variation_id][$axisId] = html_entity_decode((string) $v->value_name, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?: 'Unnamed';
            }
            if (count($axes) > self::MAX_AXES) {
                return ['skus' => [], 'kind' => 'combos', 'keys' => [],
                    'refused' => 'TikTok Shop supports at most three variation axes; this product has ' . count($axes) . '.'];
            }

            $uploaded = [];
            $skus = [];
            $keys = [];
            foreach ($combos as $combo) {
                $sellerSku = trim((string) $combo->sku) !== '' ? trim((string) $combo->sku) : (($catalog->sku ?: $productId) . '-' . $combo->id);
                $base = \App\Channels\Listings\VariationPrices::base($own, $productId, $combo->sku, (float) $combo->absolute_price > 0 ? (float) $combo->absolute_price : $basePrice);
                $sku = [
                    'seller_sku' => $sellerSku,
                    'sales_attributes' => array_map(
                        fn ($axisId) => ['name' => $axes[$axisId], 'value_name' => $comboAxes[(int) $combo->id][$axisId] ?? 'Unnamed'],
                        array_keys($axes)
                    ),
                    'price' => $money($priceFor($base)),
                    'inventory' => $inventory((int) $combo->quantity),
                ];
                $img = trim((string) ($combo->image ?? ''));
                if ($img !== '' && $uploadUri !== null) {
                    $uploaded[$img] ??= $uploadUri($img);
                    if ($uploaded[$img]) {
                        $sku['sku_img'] = ['uri' => $uploaded[$img]];
                    }
                }
                if (isset($existing[$sellerSku])) {
                    $sku['id'] = (string) $existing[$sellerSku];
                }
                $skus[] = $sku;
                $keys[] = $sellerSku;
            }

            return ['skus' => $skus, 'kind' => 'combos', 'keys' => $keys, 'refused' => null];
        }

        $variationValues = $this->variationValues( $langId, $productId);
        if (!empty($variationValues)) {
            $variationValues = array_values(array_filter($variationValues, fn ($ov) => \App\Channels\Listings\ListingVariations::allows($hidden, $productId, $ov->sku)));
            if ($variationValues === []) {
                return ['skus' => [], 'kind' => 'pov', 'keys' => [], 'refused' => \App\Channels\Listings\ListingVariations::noneSoldMessage('TikTok Shop')];
            }
        }
        if (!empty($variationValues)) {
            $skus = [];
            $keys = [];
            $axisName = $variationValues[0]->variation_type_name;
            foreach ($variationValues as $ov) {
                $sellerSku = trim((string) $ov->sku) !== '' ? trim((string) $ov->sku) : ($catalog->sku . '-' . $ov->variation_value_id);
                $sku = [
                    'seller_sku' => $sellerSku,
                    'sales_attributes' => [['name' => $axisName, 'value_name' => $ov->value_name]],
                    'price' => $money($priceFor(\App\Channels\Listings\VariationPrices::base($own, $productId, $ov->sku, $this->variationPrice($basePrice, $ov)))),
                    'inventory' => $inventory((int) $ov->quantity),
                ];
                $id = $existing[$sellerSku] ?? $existing[(string) $ov->variation_value_id] ?? $existing[(int) $ov->variation_value_id] ?? null;
                if ($id) {
                    $sku['id'] = (string) $id;
                }
                $skus[] = $sku;
                $keys[] = $sellerSku;
            }

            return ['skus' => $skus, 'kind' => 'pov', 'keys' => $keys, 'refused' => null];
        }

        $sellerSku = $catalog->sku ?: (string) $productId;
        $startPrice = \Extensions\TikTok\Models\TikTokListing::ownPrices([$productId])[$productId] ?? $basePrice;
        $sku = [
            'seller_sku' => $sellerSku,
            'price' => $money($priceFor($startPrice)),
            'inventory' => $inventory((int) ($catalog->quantity ?? 0)),
        ];
        $single = $existing['__single__'] ?? null;
        if ($single) {
            $sku['id'] = (string) $single;
        }

        return ['skus' => [$sku], 'kind' => 'single', 'keys' => [$sellerSku], 'refused' => null];
    }

    public function storedIds(array $built, array $returnedSkus): ?string
    {
        if ($built['kind'] === 'single') {
            return isset($returnedSkus[0]['id']) ? (string) $returnedSkus[0]['id'] : null;
        }
        $byKey = [];
        foreach ($returnedSkus as $r) {
            if (isset($r['seller_sku'], $r['id'])) {
                $byKey[(string) $r['seller_sku']] = (string) $r['id'];
            }
        }
        $map = [];
        foreach ($built['keys'] as $i => $key) {
            $id = $byKey[$key] ?? ($returnedSkus[$i]['id'] ?? null);
            if ($id !== null) {
                $map[$key] = (string) $id;
            }
        }

        return $map ? json_encode($map) : null;
    }

    public static function existingIds(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if ($raw[0] === '{') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return ['__single__' => $raw];
    }

    public static function withVariations(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === []) {
            return [];
        }
        $combos = DB::table('product_variations')->whereIn('product_id', $productIds)->distinct()->pluck('product_id');
        $values = DB::table('product_variation_values as pov')
            ->join('product_variation_types as po', 'po.product_variation_type_id', '=', 'pov.product_variation_type_id')
            ->join('variation_types as o', 'o.variation_type_id', '=', 'po.variation_type_id')
            ->whereIn('pov.product_id', $productIds)
            ->whereIn('o.type', ['select', 'radio'])
            ->distinct()->pluck('pov.product_id');

        return array_fill_keys($combos->merge($values)->map(fn ($v) => (int) $v)->unique()->values()->all(), true);
    }

    public function variationValues( int $langId, int $productId): array
    {
        return DB::table('product_variation_values as pov')
            ->join('product_variation_types as po', 'po.product_variation_type_id', '=', 'pov.product_variation_type_id')
            ->join('variation_types as o', 'o.variation_type_id', '=', 'po.variation_type_id')
            ->join('variation_type_descriptions as od', function ($j) use ($langId) {
                $j->on('od.variation_type_id', '=', 'o.variation_type_id')->where('od.language_id', '=', $langId);
            })
            ->join('variation_value_descriptions as ovd', function ($j) use ($langId) {
                $j->on('ovd.variation_value_id', '=', 'pov.variation_value_id')->where('ovd.language_id', '=', $langId);
            })
            ->where('pov.product_id', $productId)
            ->whereIn('o.type', ['select', 'radio'])
            ->orderBy('po.product_variation_type_id')
            ->orderBy('pov.product_variation_value_id')
            ->get([
                'pov.product_variation_value_id', 'pov.variation_value_id', 'pov.sku', 'pov.quantity',
                'pov.price', 'pov.price_prefix', 'pov.absolute_price',
                'ovd.name as value_name', 'od.name as variation_type_name',
            ])
            ->all();
    }

    public function variationPrice(float $basePrice, object $ov): float
    {
        if ((float) ($ov->absolute_price ?? 0) > 0) {
            return (float) $ov->absolute_price;
        }
        $modifier = (float) ($ov->price ?? 0);

        return ($ov->price_prefix ?? '+') === '-' ? max(0, $basePrice - $modifier) : $basePrice + $modifier;
    }
}
