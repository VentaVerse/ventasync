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
    /* Shopee refuses an item whose dearest variation costs more than five
     * times its cheapest, promotion prices included - the #2062 refusal:
     * "The price ratio of the most expensive to cheapest variation
     * exceeds 5. Please adjust the prices." Knowable from our own prices
     * before the item exists, so it is checked there (#192). */
    private const PRICE_RATIO_LIMIT = 5.0;

    /**
     * What Shopee will knowably refuse about this product's variations,
     * checked BEFORE the parent item is created (#192): a parent that
     * goes up and then loses its variations is a half-listed item
     * selling the wrong thing. Null means nothing knowable stands in the
     * way (including "no variations at all").
     */
    public function refusalFor(int $productId, \Closure $priceFor, int $storeId = 0): ?string
    {
        $pfx = (string) config('catalog.prefix');
        $all = DB::table('product_option_combinations')
            ->where('product_id', $productId)
            ->get(['sku', 'absolute_price']);
        if ($all->isEmpty()) {
            return null;
        }
        // Only the variations this store sells count, so switching the dear
        // one off on Shopee is how a spread Shopee refuses is answered.
        $hidden = \App\Integrations\Listings\ListingVariations::hidden('shopee', $storeId, [$productId]);
        $combos = $all->filter(fn ($c) => \App\Integrations\Listings\ListingVariations::allows($hidden, $productId, $c->sku))->values();
        if ($combos->isEmpty()) {
            return \App\Integrations\Listings\ListingVariations::noneSoldMessage('Shopee');
        }
        if ($combos->count() > 50) {
            return 'Shopee accepts at most 50 variations per item; this product has ' . $combos->count() . '.';
        }

        $base = (float) (DB::table($pfx . 'product')->where('product_id', $productId)->value('price') ?? 0);
        $prices = $combos->map(function ($c) use ($base, $priceFor) {
            $core = $c->absolute_price !== null ? (float) $c->absolute_price : $base;

            return (float) $priceFor($core);
        })->filter(fn ($p) => $p > 0);
        if ($prices->count() < 2) {
            return null;
        }

        $min = (float) $prices->min();
        $max = (float) $prices->max();
        if ($min > 0 && $max / $min > self::PRICE_RATIO_LIMIT) {
            return 'the dearest variation costs more than 5 times the cheapest ('
                . \App\Support\Money::base($min) . ' to ' . \App\Support\Money::base($max)
                . ' after the price rule), and Shopee refuses that spread. Adjust the variation prices, then push.';
        }

        return null;
    }

    /**
     * Add the catalogue's missing variations to an ALREADY-LISTED item
     * (Phase F, #184). The owner's daily loop - add a variation in the ERP,
     * then unlink and re-match hoping it sticks - becomes one push:
     *
     *   1. read what Shopee holds (one get_model_list);
     *   2. find the catalogue combinations Shopee is missing, by SKU;
     *   3. extend the tier structure by APPENDING options only - existing
     *      options keep their positions, so existing models keep their
     *      meaning (update_tier_variation);
     *   4. add_model for the new combinations, priced by the push's rule.
     *
     * Anything that cannot be done safely blind is refused in plain words
     * before any write: a tier-count mismatch, a price spread past
     * Shopee's 5x rule (existing models included), an unanswered read.
     * A flat item (no models yet) ships its whole structure through the
     * existing init path instead.
     *
     * A caller that has already read the item passes that read (readModels())
     * so the item is not asked twice.
     *
     * @param null|array{ok: bool, reason: string, response: array} $read
     * @return array{ok: bool, message: string, added: int}
     */
    public function pushMissingModels(ShopeeClient $client, array $auth, int $itemId, int $productId, \Closure $priceFor, ?\Closure $uploadImageId = null, ?array $read = null): array
    {
        if ($read !== null) {
            if (!($read['ok'] ?? false)) {
                return ['ok' => false, 'message' => 'Shopee did not answer for the item\'s variations: ' . (string) ($read['reason'] ?? 'no answer') . ' Nothing was changed.', 'added' => 0];
            }
            $resp = (array) ($read['response'] ?? []);
        } else {
            $modelRes = $client->shopGet(
                $auth['mode'], (int) $auth['partner_id'], (string) $auth['partner_key'],
                (string) $auth['access_token'], (int) $auth['shop_id'],
                '/api/v2/product/get_model_list', ['item_id' => $itemId]
            );
            $body = $modelRes['body'] ?? [];
            if (!($modelRes['ok'] ?? false) || (($body['error'] ?? '') !== '' && ($body['error'] ?? null) !== null)) {
                return ['ok' => false, 'message' => 'Shopee did not answer for the item\'s variations: ' . (string) ($body['message'] ?? 'no answer') . ' Nothing was changed.', 'added' => 0];
            }
            $resp = $body['response'] ?? $body;
        }
        $shopeeModels = (array) ($resp['model'] ?? []);
        $shopeeTiers = (array) ($resp['tier_variation'] ?? []);

        // A flat item gains its first variations through the init path.
        if (empty($shopeeModels)) {
            $r = $this->pushForProduct($client, $auth, $itemId, $productId, $priceFor, $uploadImageId);
            if ($r === null) {
                return ['ok' => true, 'message' => 'The catalogue has no variations for this product; nothing to add.', 'added' => 0];
            }

            return ['ok' => (bool) $r['ok'], 'message' => $r['ok']
                ? 'The item had no variations; its whole structure went up (' . $r['models'] . ' variations).'
                : $r['message'], 'added' => $r['ok'] ? (int) $r['models'] : 0];
        }

        // The catalogue's side: combinations, axes, and each combo's values.
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $combos = DB::table('product_option_combinations')
            ->where('product_id', $productId)
            ->orderBy('sort_order')
            ->get(['id', 'sku', 'quantity', 'absolute_price', 'image']);
        $hidden = \App\Integrations\Listings\ListingVariations::hidden('shopee', (int) ($auth['store_id'] ?? 0), [$productId]);
        $combos = $combos->filter(fn ($c) => \App\Integrations\Listings\ListingVariations::allows($hidden, $productId, $c->sku))->values();
        if ($combos->isEmpty()) {
            return ['ok' => true, 'message' => 'The catalogue has no variations for this product; nothing to add.', 'added' => 0];
        }

        $existingSkus = [];
        foreach ($shopeeModels as $m) {
            $sku = strtolower(trim((string) ($m['model_sku'] ?? '')));
            if ($sku !== '') {
                $existingSkus[$sku] = true;
            }
        }
        $missing = $combos->filter(function ($c) use ($existingSkus) {
            $sku = strtolower(trim((string) ($c->sku ?? '')));

            return $sku !== '' && !isset($existingSkus[$sku]);
        })->values();
        if ($missing->isEmpty()) {
            return ['ok' => true, 'message' => 'Every catalogue variation is already on Shopee.', 'added' => 0];
        }

        $values = DB::table('product_option_combination_values as cv')
            ->join($pfx . 'product_option_value as pov', 'cv.product_option_value_id', '=', 'pov.product_option_value_id')
            ->join($pfx . 'option_value_description as ovd', function ($j) use ($langId) {
                $j->on('pov.option_value_id', '=', 'ovd.option_value_id')
                  ->where('ovd.language_id', '=', $langId);
            })
            ->whereIn('cv.combination_id', $combos->pluck('id')->all())
            ->orderBy('pov.product_option_id')
            ->get(['cv.combination_id', 'pov.product_option_id', 'ovd.name as value_name']);
        $comboValues = []; // combination_id => list of value names in axis order
        $axisSet = [];
        foreach ($values as $v) {
            $comboValues[(int) $v->combination_id][(int) $v->product_option_id] =
                html_entity_decode((string) $v->value_name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $axisSet[(int) $v->product_option_id] = true;
        }
        $axisIds = array_keys($axisSet);

        if (count($axisIds) !== count($shopeeTiers)) {
            return ['ok' => false, 'message' => 'The catalogue arranges this product in ' . count($axisIds)
                . ' option ' . (count($axisIds) === 1 ? 'axis' : 'axes') . ' but the Shopee listing has ' . count($shopeeTiers)
                . ' tier' . (count($shopeeTiers) === 1 ? '' : 's') . '. Restructuring a live listing is not safe to do blind - adjust it in Seller Centre, then press Re-read variations.', 'added' => 0];
        }

        /* The 5x rule holds across the WHOLE item, existing models
         * included - adding a cheap variation next to a dear one is
         * exactly how the spread breaks. */
        $parentPrice = (float) (DB::table($pfx . 'product')->where('product_id', $productId)->value('price') ?? 0);
        $prices = [];
        foreach ($shopeeModels as $m) {
            $existing = (float) ($m['price_info'][0]['original_price'] ?? 0);
            if ($existing > 0) {
                $prices[] = $existing;
            }
        }
        $newModels = [];
        foreach ($missing as $combo) {
            $core = (float) $combo->absolute_price > 0 ? (float) $combo->absolute_price : $parentPrice;
            $prices[] = (float) $priceFor($core);
        }
        $pMin = min($prices);
        $pMax = max($prices);
        if ($pMin > 0 && $pMax / $pMin > self::PRICE_RATIO_LIMIT) {
            return ['ok' => false, 'message' => 'Adding ' . ($missing->count() === 1 ? 'this variation' : 'these variations')
                . ' would make the dearest cost more than 5 times the cheapest ('
                . \App\Support\Money::base($pMin) . ' to ' . \App\Support\Money::base($pMax)
                . ' across the whole item), and Shopee refuses that spread. Adjust the prices, then push.', 'added' => 0];
        }

        /* Place each missing combination on the tiers, appending options
         * where a value is new. Existing entries pass through VERBATIM so
         * nothing already live moves. */
        $newTiers = array_values(array_map(fn ($t) => [
            'name' => (string) ($t['name'] ?? 'Variation'),
            'option_list' => array_values((array) ($t['option_list'] ?? [])),
        ], $shopeeTiers));
        $extended = 0;
        foreach ($missing as $combo) {
            $tierIndex = [];
            foreach ($axisIds as $tierN => $axisId) {
                $valueName = trim((string) ($comboValues[(int) $combo->id][$axisId] ?? ''));
                if ($valueName === '') {
                    return ['ok' => false, 'message' => 'Variation ' . trim((string) $combo->sku)
                        . ' is missing a value on one of its option axes, so it cannot be placed on the tiers. Fix the variation in the catalogue first.', 'added' => 0];
                }
                $found = null;
                foreach ($newTiers[$tierN]['option_list'] as $i => $entry) {
                    if (strcasecmp(trim((string) ($entry['option'] ?? '')), $valueName) === 0) {
                        $found = $i;
                        break;
                    }
                }
                if ($found === null) {
                    $entry = ['option' => mb_substr($valueName, 0, 50)];
                    if ($tierN === 0 && $uploadImageId !== null && trim((string) ($combo->image ?? '')) !== '') {
                        $imageId = $uploadImageId(trim((string) $combo->image));
                        if ($imageId !== null && $imageId !== '') {
                            $entry['image'] = ['image_id' => $imageId];
                        }
                    }
                    $newTiers[$tierN]['option_list'][] = $entry;
                    $found = count($newTiers[$tierN]['option_list']) - 1;
                    $extended++;
                }
                $tierIndex[] = $found;
            }

            $core = (float) $combo->absolute_price > 0 ? (float) $combo->absolute_price : $parentPrice;
            $newModels[] = [
                'tier_index' => $tierIndex,
                'model_sku' => mb_substr(trim((string) $combo->sku), 0, 100),
                'original_price' => $priceFor($core),
                'seller_stock' => [['stock' => max(0, (int) $combo->quantity)]],
            ];
        }

        if ($extended > 0) {
            $tierPayload = ['item_id' => $itemId, 'tier_variation' => $newTiers];
            $tierRes = $client->shopPost(
                $auth['mode'], (int) $auth['partner_id'], (string) $auth['partner_key'],
                (string) $auth['access_token'], (int) $auth['shop_id'],
                '/api/v2/product/update_tier_variation', [], $tierPayload
            );
            ShopeeApiLog::safeCreate([
                'pack' => 'shopee.products.update_tier_variation', 'method' => 'POST',
                'api_path' => '/api/v2/product/update_tier_variation', 'auth_required' => true,
                'request_params' => $tierPayload,
                'response_status' => $tierRes['status'] ?? null, 'ok' => (bool) ($tierRes['ok'] ?? false),
                'response_body' => $tierRes['body'] ?? null, 'user_id' => auth()->id(),
            ]);
            $tierBody = $tierRes['body'] ?? [];
            if (!($tierRes['ok'] ?? false) || (($tierBody['error'] ?? '') !== '' && ($tierBody['error'] ?? null) !== null)) {
                return ['ok' => false, 'message' => 'Shopee refused the new tier option: '
                    . (string) ($tierBody['message'] ?? ($tierBody['error'] ?? 'no answer')) . ' Nothing was added.', 'added' => 0];
            }
        }

        $addPayload = ['item_id' => $itemId, 'model_list' => $newModels];
        $addRes = $client->shopPost(
            $auth['mode'], (int) $auth['partner_id'], (string) $auth['partner_key'],
            (string) $auth['access_token'], (int) $auth['shop_id'],
            '/api/v2/product/add_model', [], $addPayload
        );
        ShopeeApiLog::safeCreate([
            'pack' => 'shopee.products.add_model', 'method' => 'POST',
            'api_path' => '/api/v2/product/add_model', 'auth_required' => true,
            'request_params' => $addPayload,
            'response_status' => $addRes['status'] ?? null, 'ok' => (bool) ($addRes['ok'] ?? false),
            'response_body' => $addRes['body'] ?? null, 'user_id' => auth()->id(),
        ]);
        $addBody = $addRes['body'] ?? [];
        if (!($addRes['ok'] ?? false) || (($addBody['error'] ?? '') !== '' && ($addBody['error'] ?? null) !== null)) {
            return ['ok' => false, 'message' => 'Shopee refused the new variation'
                . (count($newModels) === 1 ? '' : 's') . ': '
                . (string) ($addBody['message'] ?? ($addBody['error'] ?? 'no answer')), 'added' => 0];
        }

        return [
            'ok' => true,
            'message' => count($newModels) . ' variation' . (count($newModels) === 1 ? '' : 's') . ' added to Shopee'
                . ($extended > 0 ? ' (' . $extended . ' new tier option' . ($extended === 1 ? '' : 's') . ')' : '') . '.',
            'added' => count($newModels),
        ];
    }

    /**
     * One get_model_list read of an item, in the shape every step of an
     * update shares: the rename, the store's variation switches and the add
     * path all work from it. A failed read carries Shopee's reason in plain
     * words and an empty response.
     *
     * @return array{ok: bool, reason: string, response: array}
     */
    public function readModels(ShopeeClient $client, array $auth, int $itemId): array
    {
        try {
            $res = $client->shopGet(
                $auth['mode'], (int) $auth['partner_id'], (string) $auth['partner_key'],
                (string) $auth['access_token'], (int) $auth['shop_id'],
                '/api/v2/product/get_model_list', ['item_id' => $itemId]
            );
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => \App\Support\TransportError::plain($e, 'Shopee'), 'response' => []];
        }

        $body = is_array($res['body'] ?? null) ? $res['body'] : [];
        if (!($res['ok'] ?? false) || trim((string) ($body['error'] ?? '')) !== '') {
            return ['ok' => false, 'reason' => \App\Support\MarketplaceAnswer::plain('Shopee', ['ok' => false, 'body' => $body]), 'response' => []];
        }

        return ['ok' => true, 'reason' => '', 'response' => is_array($body['response'] ?? null) ? $body['response'] : []];
    }

    /**
     * Remove a just-created item whose variations were refused (#192):
     * delete it at Shopee and take this push's link and cache rows with
     * it, so nothing half-listed stays up and nothing here claims it is.
     * Returns false when Shopee would not delete - the caller must then
     * say the half-listed item is still standing.
     */
    public function undoCreate(ShopeeClient $client, array $auth, int $productId, int $itemId): bool
    {
        $path = '/api/v2/product/delete_item';
        $result = $client->shopPost(
            $auth['mode'], (int) $auth['partner_id'], (string) $auth['partner_key'],
            (string) $auth['access_token'], (int) $auth['shop_id'],
            $path, [], ['item_id' => $itemId]
        );
        ShopeeApiLog::safeCreate([
            'pack' => 'shopee.products.delete_item.undo_half_create', 'method' => 'POST', 'api_path' => $path,
            'auth_required' => true, 'request_params' => ['item_id' => $itemId],
            'response_status' => $result['status'] ?? null, 'ok' => (bool) ($result['ok'] ?? false),
            'response_body' => $result['body'] ?? null, 'user_id' => auth()->id(),
        ]);

        $ok = ($result['ok'] ?? false) && (($result['body']['error'] ?? '') === '' || ($result['body']['error'] ?? null) === null);

        /* THE LINK GOES ONLY WHEN THE ITEM IS ACTUALLY GONE.
         *
         * delete_item answering ok was taken as proof, and it is not: an
         * item that survives the call - refused for a reason the body does
         * not carry, unlisted rather than removed, deleted late - left a
         * live item on Shopee with nothing here pointing at it. That is
         * the state that makes the next push create a second one, and on a
         * product with no SKU nothing can ever find it again (the owner,
         * 2026-09-09). So the shop is asked, and a link is dropped only on
         * an answer that says the item is not there. Shopee not answering
         * keeps the link: a link to an item that is gone is a smaller
         * wrong than a duplicate nobody can see. */
        if ($ok && $this->itemIsGone($client, $auth, $itemId)) {
            ShopeeProductLink::query()->where('product_id', $productId)->where('shopee_item_id', $itemId)->delete();
            ShopeeItemCache::query()->where('shopee_item_id', $itemId)->delete();
        }

        return $ok;
    }

    /**
     * Did the delete really take? Only a definitive answer counts: the
     * shop not answering, or answering with an error of its own, proves
     * nothing and must not be read as absence.
     */
    private function itemIsGone(ShopeeClient $client, array $auth, int $itemId): bool
    {
        try {
            $res = $client->shopGet(
                $auth['mode'], (int) $auth['partner_id'], (string) $auth['partner_key'],
                (string) $auth['access_token'], (int) $auth['shop_id'],
                '/api/v2/product/get_item_base_info',
                ['item_id_list' => (string) $itemId]
            );
        } catch (\Throwable $e) {
            return false;
        }

        $body = is_array($res['body'] ?? null) ? $res['body'] : [];
        $err = strtolower((string) ($body['error'] ?? ''));

        if ($err !== '') {
            // Only an error that NAMES the item as missing is an answer.
            return str_contains($err, 'not_found') || str_contains($err, 'not_exist');
        }

        if (! ($res['ok'] ?? false)) {
            return false;
        }

        foreach ($body['response']['item_list'] ?? [] as $item) {
            if ((int) ($item['item_id'] ?? 0) === $itemId) {
                return strtoupper((string) ($item['item_status'] ?? '')) === 'DELETED';
            }
        }

        // A successful answer that does not hold the item: the shop has none.
        return true;
    }

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
        $built = $this->build($productId, $priceFor, $uploadImageId, (int) ($auth['store_id'] ?? 0));
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
        if ($answered !== []) {
            ShopeeListingStates::rememberHeld(
                (int) ($auth['store_id'] ?? 0) ?: (int) (\Extensions\shopee\Models\ShopeeSetting::defaultStore()?->id ?? 0),
                $productId,
                array_map(fn ($m) => (string) ($m['model_sku'] ?? ''), $answered)
            );
        }

        return ['ok' => true, 'message' => '', 'models' => count($built['models']), 'stored_links' => $stored];
    }

    /**
     * The tier structure and models, from the product's combinations.
     *
     * @return null|array null = no combinations; ['refused' => reason]
     *         when the structure cannot go to Shopee as it stands.
     */
    private function build(int $productId, \Closure $priceFor, ?\Closure $uploadImageId, int $storeId = 0): ?array
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $all = DB::table('product_option_combinations')
            ->where('product_id', $productId)
            ->orderBy('sort_order')
            ->get(['id', 'sku', 'quantity', 'absolute_price', 'image', 'status']);

        if ($all->isEmpty()) {
            return null;
        }
        $hidden = \App\Integrations\Listings\ListingVariations::hidden('shopee', $storeId, [$productId]);
        $combos = $all->filter(fn ($c) => \App\Integrations\Listings\ListingVariations::allows($hidden, $productId, $c->sku))->values();
        if ($combos->isEmpty()) {
            return ['refused' => \App\Integrations\Listings\ListingVariations::noneSoldMessage('Shopee')];
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
