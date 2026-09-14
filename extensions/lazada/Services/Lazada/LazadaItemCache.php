<?php

namespace Extensions\lazada\Services\Lazada;

use Extensions\lazada\Models\LazadaApiLog;
use Extensions\lazada\Models\LazadaProduct;
use Extensions\lazada\Models\LazadaProductVariant;
use Extensions\lazada\Models\LazadaSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The item read-back, out of the god controller (Phase C, #184).
 *
 * One /product/item/get answer feeds four caches: the live-status MIRROR
 * (#184 C write-through), the sync-outcome columns, the variant rows
 * (sku_id / shop_sku / product_option_value_id), and the group pivot.
 * This existed as FIVE private copies - refreshLazadaProductCache and
 * fetchAndCacheLazadaVariants in the product controller, near-identical
 * twins of both in the group controller, and a third variant fetcher in
 * the stock-push command - drifting apart one edit at a time. The $pack
 * argument keeps each caller's audit-log identity.
 */
class LazadaItemCache
{
/**
/**
 * Full cache refresh for a Lazada product: variants, status, stale cleanup, sync_status, error fields.
 */
public function refreshListing(LazadaProduct $listing, object $setting, LazadaClient $client, string $pack = 'lazada.product.item.get.cache'): void
{
    $itemId = (string) ($listing->lazada_item_id ?? '');
    if ($itemId === '') return;

    $creds = LazadaSetting::activeCredentials($setting);

    $apiPath = '/product/item/get';
    $params = [
        'app_key'      => (string) $creds['app_key'],
        'sign_method'  => 'sha256',
        'timestamp'    => (string) round(microtime(true) * 1000),
        'access_token' => (string) $creds['access_token'],
        'item_id'      => $itemId,
    ];
    $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);

    $res = $client->get((string) $setting->region, $apiPath, $params);
    $body = $res['body'] ?? [];
    $data = $body['data'] ?? $body;

    $apiCode = is_array($body) ? (string)($body['code'] ?? '') : '';
    LazadaApiLog::safeCreate([
        'pack'            => $pack,
        'method'          => 'GET',
        'api_path'        => $apiPath,
        'auth_required'   => true,
        'request_params'  => $params,
        'response_status' => (int) ($res['status'] ?? 0),
        'ok'              => ($res['ok'] ?? false) && ($apiCode === '' || $apiCode === '0'),
        'response_body'   => $body,
        'user_id'         => auth()->id(),
    ]);

    if (empty($data) || !is_array($data)) return;

    // 1. Write the answer through to the mirror (#184 C). This read runs
    // after uploads and ID matches, so the status in hand is exactly the
    // freshness the row needs - the old code wrote it to the dead `status`
    // column nothing reads, and the mirror waited for the 30-minute sweep.
    $lazadaStatus = strtolower(trim((string) ($data['status'] ?? '')));
    if ($lazadaStatus !== '') {
        $listing->live_status = $lazadaStatus;
        $listing->live_checked_at = now();
    }

    // 2. Clear stale sync error fields
    $listing->last_sync_ok = true;
    $listing->last_sync_error_code = null;
    $listing->last_sync_error_message = null;
    $listing->last_synced_at = now();
    $listing->save();

    // 3. Refresh variants (sku_id, shop_sku, pov_id mapping)
    $pfx = (string) config('catalog.prefix');
    $skuToPovId = [];
    $erpOvs = DB::table($pfx . 'product_option_value')
        ->where('product_id', (int) $listing->product_id)
        ->whereNotNull('sku')
        ->where('sku', '!=', '')
        ->get(['product_option_value_id', 'sku']);
    foreach ($erpOvs as $ov) {
        $skuToPovId[trim((string) $ov->sku)] = (int) $ov->product_option_value_id;
    }

    $skus = data_get($data, 'skus') ?? data_get($data, 'Skus.Sku') ?? [];
    $activeLazadaSkus = [];

    foreach ($skus as $s) {
        $sellerSku = trim((string) ($s['SellerSku'] ?? $s['seller_sku'] ?? ''));
        $skuId = $s['SkuId'] ?? $s['sku_id'] ?? $s['skuId'] ?? null;
        $shopSku = $s['ShopSku'] ?? $s['shop_sku'] ?? null;

        if ($skuId === null || $skuId === '') continue;

        $povId = $skuToPovId[$sellerSku] ?? null;
        $activeLazadaSkus[] = $sellerSku;

        try {
            LazadaProductVariant::updateOrCreate(
                ['lazada_product_id' => $listing->id, 'seller_sku' => $sellerSku],
                [
                    'sku_id' => (int) $skuId,
                    'shop_sku' => $shopSku ? (string) $shopSku : null,
                    'product_option_value_id' => $povId,
                ]
            );
        } catch (\Throwable $ex) {}
    }

    // The item's seller SKUs, remembered only from an answer that holds them.
    if (($res['ok'] ?? false) && ($apiCode === '' || $apiCode === '0') && is_array($skus) && $skus !== []) {
        LazadaListingStates::rememberHeld(
            (int) ($listing->lazada_setting_id ?? 0) ?: (int) ($setting->id ?? 0),
            (int) $listing->product_id,
            array_map(fn ($s) => is_array($s) ? (string) ($s['SellerSku'] ?? $s['seller_sku'] ?? '') : '', $skus)
        );
    }

    // 4. Remove stale variants no longer on Lazada
    if (!empty($activeLazadaSkus)) {
        LazadaProductVariant::where('lazada_product_id', $listing->id)
            ->whereNotIn('seller_sku', $activeLazadaSkus)
            ->delete();
    }

    // 5. Update sync_status on group pivot
    DB::table('lazada_product_group_products')
        ->where('lazada_product_id', $listing->id)
        ->update(['sync_status' => 'synced', 'push_error' => null]);
}

/**
 * Fetch Lazada product variants via /product/item/get, cache SkuId in lazada_product_variants.
 * Returns array of ['seller_sku' => ..., 'sku_id' => ..., 'shop_sku' => ...].
 */
public function fetchAndCacheVariants(LazadaProduct $listing, object $setting, LazadaClient $client, string $pack = 'lazada.product.item.get.variants'): array
{
    $itemId = (string) ($listing->lazada_item_id ?? '');
    if ($itemId === '') return [];

    $creds = LazadaSetting::activeCredentials($setting);

    $apiPath = '/product/item/get';
    $timestamp = (string) round(microtime(true) * 1000);
    $params = [
        'app_key' => (string) $creds['app_key'],
        'sign_method' => 'sha256',
        'timestamp' => $timestamp,
        'access_token' => (string) $creds['access_token'],
        'item_id' => $itemId,
    ];
    $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);

    $res = $client->get((string) $setting->region, $apiPath, $params);
    $body = $res['body'] ?? [];
    $data = $body['data'] ?? $body;

    $apiCode = is_array($body) ? (string)($body['code'] ?? '') : '';
    LazadaApiLog::safeCreate([
        'pack'            => $pack,
        'method'          => 'GET',
        'api_path'        => $apiPath,
        'auth_required'   => true,
        'request_params'  => $params,
        'response_status' => (int) ($res['status'] ?? 0),
        'ok'              => ($res['ok'] ?? false) && ($apiCode === '' || $apiCode === '0'),
        'response_body'   => $body,
        'user_id'         => auth()->id(),
    ]);

    $skus = data_get($data, 'skus') ?? data_get($data, 'Skus.Sku') ?? [];
    if (!is_array($skus)) return [];

    $pfx = (string) config('catalog.prefix');
    $skuToPovId = [];
    $erpOvs = DB::table($pfx . 'product_option_value')
        ->where('product_id', (int) $listing->product_id)
        ->whereNotNull('sku')
        ->where('sku', '!=', '')
        ->get(['product_option_value_id', 'sku']);
    foreach ($erpOvs as $ov) {
        $skuToPovId[trim((string) $ov->sku)] = (int) $ov->product_option_value_id;
    }

    $variants = [];
    foreach ($skus as $s) {
        $sellerSku = trim((string) ($s['SellerSku'] ?? $s['seller_sku'] ?? ''));
        $skuId = $s['SkuId'] ?? $s['sku_id'] ?? $s['skuId'] ?? null;
        $shopSku = $s['ShopSku'] ?? $s['shop_sku'] ?? null;

        if ($skuId === null || $skuId === '') continue;

        $povId = $skuToPovId[$sellerSku] ?? null;

        $variants[] = [
            'seller_sku' => $sellerSku,
            'sku_id' => (int) $skuId,
            'shop_sku' => $shopSku ? (string) $shopSku : null,
        ];

        try {
            LazadaProductVariant::updateOrCreate(
                ['lazada_product_id' => $listing->id, 'seller_sku' => $sellerSku],
                [
                    'sku_id' => (int) $skuId,
                    'shop_sku' => $shopSku ? (string) $shopSku : null,
                    'product_option_value_id' => $povId,
                ]
            );
        } catch (\Throwable $ex) {}
    }

    // The item's seller SKUs, remembered only from an answer that holds them.
    if (($res['ok'] ?? false) && ($apiCode === '' || $apiCode === '0') && $skus !== []) {
        LazadaListingStates::rememberHeld(
            (int) ($listing->lazada_setting_id ?? 0) ?: (int) ($setting->id ?? 0),
            (int) $listing->product_id,
            array_map(fn ($s) => is_array($s) ? (string) ($s['SellerSku'] ?? $s['seller_sku'] ?? '') : '', $skus)
        );
    }

    return $variants;
}

/**
 * Resolve SellerSku => SkuId for a listing. Uses cached lazada_product_variants first, falls back to API.
 * Returns array keyed by seller_sku => sku_id.
 */
public function resolveSkuIds(LazadaProduct $listing, object $setting, LazadaClient $client, string $pack = 'lazada.product.item.get.variants'): array
{
    $map = [];

    // Try cached variants first
    $cached = LazadaProductVariant::where('lazada_product_id', $listing->id)
        ->whereNotNull('sku_id')
        ->get(['seller_sku', 'sku_id']);

    if ($cached->isNotEmpty()) {
        foreach ($cached as $v) {
            $map[trim((string) $v->seller_sku)] = (int) $v->sku_id;
        }

        // Check if all ERP variation SKUs are covered by the cache.
        // If not, the cache is stale — re-fetch from Lazada API. The diff
        // reads combinations AND option values (Phase F, #184): it read
        // POV alone before, so a multi-option product's cache was forever
        // "fresh" and a variation added in the catalogue never triggered
        // the refetch that would have found its Lazada twin.
        $pfx = (string) config('catalog.prefix');
        $erpSkus = \App\Integrations\Listings\ListingVariations::sold('lazada', (int) ($listing->lazada_setting_id ?? 0), [(int) $listing->product_id], $pfx)[(int) $listing->product_id] ?? [];

        $missingSkus = array_diff($erpSkus, array_keys($map));
        if (empty($missingSkus)) {
            return $map;
        }

        // Stale cache — re-fetch from API to pick up new/renamed Lazada variants
    }

    // Fetch from API and cache
    $variants = $this->fetchAndCacheVariants($listing, $setting, $client, $pack);
    $map = [];
    foreach ($variants as $v) {
        $map[trim((string) $v['seller_sku'])] = (int) $v['sku_id'];
    }
    return $map;
}

/**
 * Fetch Lazada SKU IDs for an item via /product/item/get and build sku_id_list entries.
 * Returns array like: ["SkuId_<itemId>_<skuId>", ...]
 *
 * This is used to make /product/remove reliable even when variant sku_id mapping isn't stored locally.
 */
public function fetchSkuIdListByItemId(\Extensions\lazada\Services\Lazada\LazadaClient $client, string $region, string $appKey, string $appSecret, string $accessToken, string $itemId): array
{
    $apiPath = '/product/item/get';
    $timestamp = (string) round(microtime(true) * 1000);

    $params = [
        'app_key' => $appKey,
        'sign_method' => 'sha256',
        'timestamp' => $timestamp,
        'access_token' => $accessToken,
        'item_id' => $itemId,
    ];
    $params['sign'] = $client->sign($apiPath, $params, $appSecret);

    // This endpoint supports GET in Lazada docs; use GET.
    $res = $client->get($region, $apiPath, $params);
    $body = $res['body'] ?? null;
    if (!is_array($body)) {
        return [];
    }

    // Try common shapes.
    $skus = data_get($body, 'data.skus')
        ?? data_get($body, 'data.Skus.Sku')
        ?? data_get($body, 'skus')
        ?? data_get($body, 'Skus.Sku');

    if (!is_array($skus)) {
        return [];
    }

    $out = [];
    foreach ($skus as $sku) {
        if (!is_array($sku)) {
            continue;
        }
        $skuId = $sku['sku_id'] ?? ($sku['SkuId'] ?? ($sku['skuId'] ?? null));
        if ($skuId === null || $skuId === '') {
            continue;
        }
        $out[] = 'SkuId_' . $itemId . '_' . (string) $skuId;
    }

    // Deduplicate
    $out = array_values(array_unique($out));
    return $out;
}
}
