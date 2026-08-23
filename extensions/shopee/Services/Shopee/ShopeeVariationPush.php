<?php

namespace Extensions\shopee\Services\Shopee;

use Extensions\shopee\Models\ShopeeApiLog;
use Extensions\shopee\Models\ShopeeItemCache;
use Extensions\shopee\Models\ShopeeProductLink;
use Illuminate\Support\Facades\DB;

/**
 * Variations ride the push (found by the owner on the first QAS push,
 * 2026-08-24: the GP-200 went up flat, its Red and Black never left).
 *
 * After add_item creates the parent, init_tier_variation gives it its
 * tier structure: one or two tiers built from the product's option axes,
 * and one model per combination carrying the combination's OWN sku,
 * stock and price (through whatever price rule the push is running on).
 * Shopee answers with the model ids, which become the model-level links
 * and cache rows the price/stock syncs and reconcile already expect.
 */
class ShopeeVariationPush
{
    /**
     * @param \Closure(float): float $priceFor the push's price rule
     * @param null|\Closure(string): ?string $uploadImageId local image path -> Shopee image_id
     * @return null|array{ok: bool, message: string, models: int}
     *         null when the product has no combinations (nothing to do)
     */
    public function pushForProduct(
        ShopeeClient $client,
        array $auth,
        int $itemId,
        int $productId,
        \Closure $priceFor,
        ?\Closure $uploadImageId = null
    ): ?array {
        $built = $this->build($productId, $priceFor, $uploadImageId);
        if ($built === null) {
            return null;
        }
        if (isset($built['refused'])) {
            return ['ok' => false, 'message' => $built['refused'], 'models' => 0];
        }

        $payload = [
            'item_id' => $itemId,
            'tier_variation' => $built['tiers'],
            'model' => $built['models'],
        ];

        $path = '/api/v2/product/init_tier_variation';

        /* Shopee asks for a pause between creating an item and giving it
         * tiers ("wait at least 5 seconds... processing may be delayed").
         * Attempt immediately - the sandbox usually takes it - and only
         * pay the wait when Shopee refuses. */
        $result = null;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $result = $client->shopPost(
                    $auth['mode'],
                    (int) $auth['partner_id'], (string) $auth['partner_key'],
                    (string) $auth['access_token'], (int) $auth['shop_id'],
                    $path, [], $payload
                );
            } catch (\Throwable $e) {
                $result = ['ok' => false, 'status' => 0, 'body' => ['error' => 'network', 'message' => $e->getMessage()]];
            }

            $apiError = is_array($result['body'] ?? null) ? (string) ($result['body']['error'] ?? '') : 'no_body';
            if (($result['ok'] ?? false) && $apiError === '') {
                break;
            }
            if ($attempt < 3) {
                sleep(4);
            }
        }

        ShopeeApiLog::safeCreate([
            'pack' => 'shopee.products.init_tier_variation', 'method' => 'POST',
            'api_path' => $path, 'auth_required' => true,
            'request_params' => $payload,
            'response_status' => $result['status'] ?? null,
            'ok' => (bool) ($result['ok'] ?? false),
            'response_body' => $result['body'] ?? null, 'user_id' => auth()->id(),
        ]);

        $apiError = is_array($result['body'] ?? null) ? (string) ($result['body']['error'] ?? '') : 'no_body';
        if (!($result['ok'] ?? false) || $apiError !== '') {
            $msg = is_array($result['body'] ?? null)
                ? (string) ($result['body']['message'] ?? ($result['body']['error'] ?? 'no response'))
                : 'no response';

            return ['ok' => false, 'message' => $msg, 'models' => 0];
        }

        // Shopee's answer names each model's id: store the model-level
        // links and cache rows the syncs and reconcile expect. The
        // parent-level link goes - a tiered item's stock and price live on
        // its models, exactly as the reconcile sweep stores them.
        $answered = (($result['body'] ?? [])['response']['model'] ?? []);
        $skuToModel = [];
        foreach ($answered as $m) {
            $sku = trim((string) ($m['model_sku'] ?? ''));
            $modelId = (int) ($m['model_id'] ?? 0);
            if ($sku !== '' && $modelId > 0) {
                $skuToModel[strtolower($sku)] = $modelId;
            }
        }

        $stored = 0;
        if (!empty($skuToModel)) {
            ShopeeProductLink::query()
                ->where('product_id', $productId)
                ->whereNull('shopee_model_id')
                ->delete();

            foreach ($built['model_skus'] as $sku) {
                $modelId = $skuToModel[strtolower($sku)] ?? null;
                if ($modelId === null) {
                    continue;
                }
                ShopeeProductLink::query()->updateOrCreate(
                    ['product_id' => $productId, 'shopee_item_id' => $itemId, 'shopee_model_id' => $modelId],
                    ['sku' => $sku]
                );
                ShopeeItemCache::query()->updateOrCreate(
                    ['shopee_item_id' => $itemId, 'shopee_model_id' => $modelId],
                    ['sku' => $sku, 'item_name' => $built['item_name']]
                );
                $stored++;
            }
        }

        return ['ok' => true, 'message' => '', 'models' => count($built['models']), 'stored_links' => $stored];
    }

    /**
     * The tier structure and models, from the product's combinations.
     *
     * @return null|array null = no combinations; ['refused' => reason]
     *         when the structure cannot go to Shopee as it stands.
     */
    private function build(int $productId, \Closure $priceFor, ?\Closure $uploadImageId): ?array
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $combos = DB::table('product_option_combinations')
            ->where('product_id', $productId)
            ->orderBy('sort_order')
            ->get(['id', 'sku', 'quantity', 'absolute_price', 'image', 'status']);

        if ($combos->isEmpty()) {
            return null;
        }
        if ($combos->count() > 50) {
            return ['refused' => 'Shopee accepts at most 50 variations per item; this product has ' . $combos->count() . '.'];
        }

        $values = DB::table('product_option_combination_values as cv')
            ->join($pfx . 'product_option_value as pov', 'cv.product_option_value_id', '=', 'pov.product_option_value_id')
            ->join($pfx . 'option_value_description as ovd', function ($j) use ($langId) {
                $j->on('pov.option_value_id', '=', 'ovd.option_value_id')
                  ->where('ovd.language_id', '=', $langId);
            })
            ->join($pfx . 'option_description as od', function ($j) use ($langId) {
                $j->on('pov.option_id', '=', 'od.option_id')
                  ->where('od.language_id', '=', $langId);
            })
            ->whereIn('cv.combination_id', $combos->pluck('id')->all())
            ->orderBy('pov.product_option_id')
            ->orderBy('pov.product_option_value_id')
            ->get([
                'cv.combination_id', 'pov.product_option_id', 'pov.product_option_value_id',
                'ovd.name as value_name', 'od.name as option_name',
            ]);

        // Axes in stable order, each with its ordered distinct options.
        $axes = [];      // product_option_id => ['name' => ..., 'options' => [pov_id => ['name' =>, 'index' =>]]]
        $comboTiers = []; // combination_id => [product_option_id => pov_id]
        foreach ($values as $v) {
            $axisId = (int) $v->product_option_id;
            if (!isset($axes[$axisId])) {
                $axes[$axisId] = [
                    'name' => html_entity_decode((string) $v->option_name, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?: 'Variation',
                    'options' => [],
                ];
            }
            $povId = (int) $v->product_option_value_id;
            if (!isset($axes[$axisId]['options'][$povId])) {
                $axes[$axisId]['options'][$povId] = [
                    'name' => html_entity_decode((string) $v->value_name, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?: 'Unnamed',
                    'index' => count($axes[$axisId]['options']),
                ];
            }
            $comboTiers[(int) $v->combination_id][$axisId] = $povId;
        }

        if (count($axes) === 0) {
            return null;
        }
        if (count($axes) > 2) {
            return ['refused' => 'Shopee supports at most two variation tiers; this product has ' . count($axes) . ' option axes.'];
        }

        $axisIds = array_keys($axes);

        // The parent's name, for the cache rows.
        $itemName = html_entity_decode((string) (DB::table($pfx . 'product_description')
            ->where('product_id', $productId)->where('language_id', $langId)
            ->value('name') ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // First-tier option images: the combination's own picture (only the
        // first tier may carry images on Shopee). Best effort - a failed
        // upload just means a plain option.
        $firstAxisImages = [];
        if ($uploadImageId !== null) {
            foreach ($combos as $combo) {
                $img = trim((string) ($combo->image ?? ''));
                $povId = $comboTiers[(int) $combo->id][$axisIds[0]] ?? null;
                if ($img === '' || $povId === null || isset($firstAxisImages[$povId])) {
                    continue;
                }
                $imageId = $uploadImageId($img);
                if ($imageId !== null && $imageId !== '') {
                    $firstAxisImages[$povId] = $imageId;
                }
            }
        }

        $tiers = [];
        foreach ($axisIds as $tierN => $axisId) {
            $optionList = [];
            foreach ($axes[$axisId]['options'] as $povId => $opt) {
                $entry = ['option' => mb_substr($opt['name'], 0, 50)];
                if ($tierN === 0 && isset($firstAxisImages[$povId])) {
                    $entry['image'] = ['image_id' => $firstAxisImages[$povId]];
                }
                $optionList[] = $entry;
            }
            $tiers[] = [
                'name' => mb_substr($axes[$axisId]['name'], 0, 26),
                'option_list' => $optionList,
            ];
        }

        $parentPrice = (float) (DB::table($pfx . 'product')->where('product_id', $productId)->value('price') ?? 0);

        $models = [];
        $modelSkus = [];
        foreach ($combos as $combo) {
            $tierIndex = [];
            foreach ($axisIds as $axisId) {
                $povId = $comboTiers[(int) $combo->id][$axisId] ?? null;
                if ($povId === null) {
                    // A combination missing a value for an axis cannot be
                    // placed in the grid; skip it rather than mis-list it.
                    continue 2;
                }
                $tierIndex[] = $axes[$axisId]['options'][$povId]['index'];
            }

            // The combination's own price through the push's rule; a
            // combination without one falls back to the parent's.
            $corePrice = (float) $combo->absolute_price > 0 ? (float) $combo->absolute_price : $parentPrice;
            $sku = trim((string) ($combo->sku ?? ''));

            $models[] = [
                'tier_index' => $tierIndex,
                'original_price' => $priceFor($corePrice),
                'model_sku' => mb_substr($sku, 0, 100),
                'seller_stock' => [['stock' => max(0, (int) $combo->quantity)]],
            ];
            $modelSkus[] = $sku;
        }

        if (empty($models)) {
            return ['refused' => 'No variation could be placed in the tier grid.'];
        }

        return [
            'tiers' => $tiers,
            'models' => $models,
            'model_skus' => $modelSkus,
            'item_name' => $itemName,
        ];
    }
}
