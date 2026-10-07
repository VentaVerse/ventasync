<?php

namespace Extensions\lazada\Services\Lazada;

use Extensions\lazada\Models\LazadaApiLog;
use Extensions\lazada\Models\LazadaProduct;
use Extensions\lazada\Models\LazadaProductVariant;
use Extensions\lazada\Models\LazadaSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LazadaItemCache
{
public function refreshListing(LazadaProduct $listing, object $setting, LazadaClient $client, string $pack = 'lazada.product.item.get.cache'): ?array
{
    $itemId = (string) ($listing->lazada_item_id ?? '');
    if ($itemId === '') return null;

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

    $answered = ($res['ok'] ?? false) && ($apiCode === '' || $apiCode === '0');
    if (! $answered) {
        $said = is_array($body) ? trim((string) ($body['message'] ?? '')) : '';
        if (preg_match('/not\s*(?:exist|found)|E0207/i', $apiCode . ' ' . $said)) {
            LazadaListingStates::on((int) ($listing->lazada_setting_id ?? 0) ?: (int) ($setting->id ?? 0))
                ->recordOutcome((int) $listing->product_id, 'Not found on Lazada' . ($said !== '' ? ': ' . rtrim($said, '.') : '') . '.');
        }

        return null;
    }

    if (empty($data) || !is_array($data)) return null;

    $lazadaStatus = strtolower(trim((string) ($data['status'] ?? '')));
    if ($lazadaStatus !== '') {
        $listing->live_status = $lazadaStatus;
        $listing->live_checked_at = now();
    }

    if (! ($listing->last_sync_ok !== null && ! $listing->last_sync_ok)) {
        $listing->last_sync_ok = true;
        $listing->last_sync_error_code = null;
        $listing->last_sync_error_message = null;
        $listing->last_synced_at = now();
    }
    $listing->save();

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

    if (($res['ok'] ?? false) && ($apiCode === '' || $apiCode === '0') && is_array($skus) && $skus !== []) {
        LazadaListingStates::rememberHeld(
            (int) ($listing->lazada_setting_id ?? 0) ?: (int) ($setting->id ?? 0),
            (int) $listing->product_id,
            array_map(fn ($s) => is_array($s) ? (string) ($s['SellerSku'] ?? $s['seller_sku'] ?? '') : '', $skus)
        );
    }

    if (!empty($activeLazadaSkus)) {
        LazadaProductVariant::where('lazada_product_id', $listing->id)
            ->whereNotIn('seller_sku', $activeLazadaSkus)
            ->delete();
    }

    DB::table('lazada_product_group_products')
        ->where('lazada_product_id', $listing->id)
        ->where(fn ($q) => $q->whereNull('sync_status')->orWhereNotIn('sync_status', ['error', 'failed']))
        ->update(['sync_status' => 'synced', 'push_error' => null]);

    return $data;
}

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

    if (($res['ok'] ?? false) && ($apiCode === '' || $apiCode === '0') && $skus !== []) {
        LazadaListingStates::rememberHeld(
            (int) ($listing->lazada_setting_id ?? 0) ?: (int) ($setting->id ?? 0),
            (int) $listing->product_id,
            array_map(fn ($s) => is_array($s) ? (string) ($s['SellerSku'] ?? $s['seller_sku'] ?? '') : '', $skus)
        );
    }

    return $variants;
}

public function resolveSkuIds(LazadaProduct $listing, object $setting, LazadaClient $client, string $pack = 'lazada.product.item.get.variants'): array
{
    $map = [];

    $cached = LazadaProductVariant::where('lazada_product_id', $listing->id)
        ->whereNotNull('sku_id')
        ->get(['seller_sku', 'sku_id']);

    if ($cached->isNotEmpty()) {
        foreach ($cached as $v) {
            $map[trim((string) $v->seller_sku)] = (int) $v->sku_id;
        }

        $pfx = (string) config('catalog.prefix');
        $erpSkus = \App\Integrations\Listings\ListingVariations::sold('lazada', (int) ($listing->lazada_setting_id ?? 0), [(int) $listing->product_id], $pfx)[(int) $listing->product_id] ?? [];

        $missingSkus = array_diff($erpSkus, array_keys($map));
        if (empty($missingSkus)) {
            return $map;
        }
    }

    $variants = $this->fetchAndCacheVariants($listing, $setting, $client, $pack);
    $map = [];
    foreach ($variants as $v) {
        $map[trim((string) $v['seller_sku'])] = (int) $v['sku_id'];
    }
    return $map;
}

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

    $res = $client->get($region, $apiPath, $params);
    $body = $res['body'] ?? null;
    if (!is_array($body)) {
        return [];
    }

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

    $out = array_values(array_unique($out));
    return $out;
}
}
