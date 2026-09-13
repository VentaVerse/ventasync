<?php

namespace Extensions\tiktok\Services\TikTok;

use Illuminate\Support\Facades\DB;

/**
 * The SKUs a TikTok product is pushed with (parity 2026-08-26).
 *
 * TikTok models variations as SKUs carrying sales attributes (up to three
 * axes) and its own picture per SKU. The push used to read
 * product_option_value alone, one SKU per option value of the FIRST axis,
 * so a two-axis product went up as its first axis only and every later
 * stock push wrote the wrong figure. This mirrors ShopeeVariationPush:
 * combinations first (each combination is one SKU with every axis named),
 * the single-axis option values when the product has no combinations,
 * and the bare product otherwise.
 *
 * The map a pivot keeps in tiktok_sku_id is keyed by SELLER SKU from
 * here on ({"VP-RED": "173..."}), which is the only key that survives a
 * second axis. Legacy maps keyed by option_value_id are still read by
 * TikTokStockPushService until the next push rewrites them.
 */
class TikTokVariationPush
{
    public const MAX_AXES = 3;

    /**
     * @param object   $erp        the product row (product_id, sku, model, price, quantity)
     * @param \Closure $priceFor   float $base => float: the channel's price rule
     * @param ?\Closure $uploadUri ?string $imagePath => ?string TikTok image uri, or null to skip SKU pictures
     * @param ?array   $existing   seller_sku => tiktok sku id (or legacy option_value_id => id) to inject on update
     * @return array{skus: array, kind: string, refused: ?string, keys: array<int, string>}
     */
    public function skus(object $erp, \Closure $priceFor, ?string $warehouseId, ?\Closure $uploadUri = null, ?array $existing = null): array
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $productId = (int) $erp->product_id;
        $basePrice = (float) ($erp->price ?? 0);
        $existing = $existing ?? [];

        $inventory = function (int $qty) use ($warehouseId): array {
            return [array_filter([
                'warehouse_id' => $warehouseId ?: null,
                'quantity' => max(0, $qty),
            ], fn ($v) => $v !== null)];
        };
        $money = fn (float $amount) => ['amount' => (string) round($amount), 'currency' => 'PHP'];

        // ── Combinations: one SKU per combination, every axis named ──
        $combos = DB::table('product_option_combinations')
            ->where('product_id', $productId)
            ->orderBy('sort_order')
            ->get(['id', 'sku', 'quantity', 'absolute_price', 'image', 'status']);

        /* Only the variations this store sells go up. TikTok deletes a SKU
         * an edit leaves out, so one switched off comes off the shop too. */
        $hidden = \App\Integrations\Listings\ListingVariations::hidden('tiktok', (int) (\Extensions\tiktok\Models\TikTokSetting::defaultStore()?->id ?? 0), [$productId]);
        if ($combos->isNotEmpty()) {
            $combos = $combos->filter(fn ($c) => \App\Integrations\Listings\ListingVariations::allows($hidden, $productId, $c->sku))->values();
            if ($combos->isEmpty()) {
                return ['skus' => [], 'kind' => 'combos', 'keys' => [], 'refused' => \App\Integrations\Listings\ListingVariations::noneSoldMessage('TikTok Shop')];
            }
        }

        if ($combos->isNotEmpty()) {
            $values = DB::table('product_option_combination_values as cv')
                ->join($pfx . 'product_option_value as pov', 'cv.product_option_value_id', '=', 'pov.product_option_value_id')
                ->join($pfx . 'option_value_description as ovd', function ($j) use ($langId) {
                    $j->on('pov.option_value_id', '=', 'ovd.option_value_id')->where('ovd.language_id', '=', $langId);
                })
                ->join($pfx . 'option_description as od', function ($j) use ($langId) {
                    $j->on('pov.option_id', '=', 'od.option_id')->where('od.language_id', '=', $langId);
                })
                ->whereIn('cv.combination_id', $combos->pluck('id')->all())
                ->orderBy('pov.product_option_id')
                ->orderBy('pov.product_option_value_id')
                ->get(['cv.combination_id', 'pov.product_option_id', 'ovd.name as value_name', 'od.name as option_name']);

            $axes = [];        // product_option_id => axis name
            $comboAxes = [];   // combination_id => [product_option_id => value name]
            foreach ($values as $v) {
                $axisId = (int) $v->product_option_id;
                $axes[$axisId] ??= html_entity_decode((string) $v->option_name, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?: 'Variation';
                $comboAxes[(int) $v->combination_id][$axisId] = html_entity_decode((string) $v->value_name, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?: 'Unnamed';
            }
            if (count($axes) > self::MAX_AXES) {
                return ['skus' => [], 'kind' => 'combos', 'keys' => [],
                    'refused' => 'TikTok Shop supports at most three variation axes; this product has ' . count($axes) . '.'];
            }

            $uploaded = [];
            $skus = [];
            $keys = [];
            foreach ($combos as $combo) {
                $sellerSku = trim((string) $combo->sku) !== '' ? trim((string) $combo->sku) : (($erp->sku ?: $erp->model ?: $productId) . '-' . $combo->id);
                $base = (float) $combo->absolute_price > 0 ? (float) $combo->absolute_price : $basePrice;
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

        // ── Single-axis option values (no combinations) ──
        $optionValues = $this->optionValues($pfx, $langId, $productId);
        if (!empty($optionValues)) {
            $optionValues = array_values(array_filter($optionValues, fn ($ov) => \App\Integrations\Listings\ListingVariations::allows($hidden, $productId, $ov->sku)));
            if ($optionValues === []) {
                return ['skus' => [], 'kind' => 'pov', 'keys' => [], 'refused' => \App\Integrations\Listings\ListingVariations::noneSoldMessage('TikTok Shop')];
            }
        }
        if (!empty($optionValues)) {
            $skus = [];
            $keys = [];
            $axisName = $optionValues[0]->option_name;
            foreach ($optionValues as $ov) {
                $sellerSku = trim((string) $ov->sku) !== '' ? trim((string) $ov->sku) : ($erp->sku . '-' . $ov->option_value_id);
                $sku = [
                    'seller_sku' => $sellerSku,
                    'sales_attributes' => [['name' => $axisName, 'value_name' => $ov->value_name]],
                    'price' => $money($priceFor($this->optionPrice($basePrice, $ov))),
                    'inventory' => $inventory((int) $ov->quantity),
                ];
                $id = $existing[$sellerSku] ?? $existing[(string) $ov->option_value_id] ?? $existing[(int) $ov->option_value_id] ?? null;
                if ($id) {
                    $sku['id'] = (string) $id;
                }
                $skus[] = $sku;
                $keys[] = $sellerSku;
            }

            return ['skus' => $skus, 'kind' => 'pov', 'keys' => $keys, 'refused' => null];
        }

        // ── The bare product ──
        $sellerSku = $erp->sku ?: ($erp->model ?: (string) $productId);
        $sku = [
            'seller_sku' => $sellerSku,
            'price' => $money($priceFor($basePrice)),
            'inventory' => $inventory((int) ($erp->quantity ?? 0)),
        ];
        $single = $existing['__single__'] ?? null;
        if ($single) {
            $sku['id'] = (string) $single;
        }

        return ['skus' => [$sku], 'kind' => 'single', 'keys' => [$sellerSku], 'refused' => null];
    }

    /**
     * What to store in tiktok_sku_id after TikTok answers: a plain id for
     * a bare product, a JSON map seller_sku => id otherwise. The answer is
     * matched by seller_sku first and by position as a fallback.
     */
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

    /** The map a pivot holds: seller_sku (or legacy option_value_id) => id; ['__single__' => id] for a bare product. */
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

    /** @return array<int, object> */
    public function optionValues(string $pfx, int $langId, int $productId): array
    {
        return DB::table($pfx . 'product_option_value as pov')
            ->join($pfx . 'product_option as po', 'po.product_option_id', '=', 'pov.product_option_id')
            ->join($pfx . 'option as o', 'o.option_id', '=', 'po.option_id')
            ->join($pfx . 'option_description as od', function ($j) use ($langId) {
                $j->on('od.option_id', '=', 'o.option_id')->where('od.language_id', '=', $langId);
            })
            ->join($pfx . 'option_value_description as ovd', function ($j) use ($langId) {
                $j->on('ovd.option_value_id', '=', 'pov.option_value_id')->where('ovd.language_id', '=', $langId);
            })
            ->where('pov.product_id', $productId)
            ->whereIn('o.type', ['select', 'radio'])
            ->orderBy('po.product_option_id')
            ->orderBy('pov.product_option_value_id')
            ->get([
                'pov.product_option_value_id', 'pov.option_value_id', 'pov.sku', 'pov.quantity',
                'pov.price', 'pov.price_prefix', 'pov.absolute_price',
                'ovd.name as value_name', 'od.name as option_name',
            ])
            ->all();
    }

    public function optionPrice(float $basePrice, object $ov): float
    {
        if ((float) ($ov->absolute_price ?? 0) > 0) {
            return (float) $ov->absolute_price;
        }
        $modifier = (float) ($ov->price ?? 0);

        return ($ov->price_prefix ?? '+') === '-' ? max(0, $basePrice - $modifier) : $basePrice + $modifier;
    }
}
