<?php

namespace Extensions\lazada\Services\Lazada;

use Extensions\lazada\Models\LazadaApiLog;
use Extensions\lazada\Models\LazadaSetting;
use Illuminate\Support\Facades\Log;

class LazadaItemMatcher
{
    private function extractItemIdFromProductsGetResult(array $result, string $sku): array
    {
        $sku = trim($sku);
        if ($sku === '') {
            return [null, 0];
        }

        $body = $result['body'] ?? null;
        if (!is_array($body)) {
            return [null, 0];
        }

        $data = $body['data'] ?? null;
        if (!is_array($data)) {
            return [null, 0];
        }

        $products = $data['products'] ?? null;
        if (!is_array($products) || count($products) === 0) {
            return [null, 0];
        }

        $matchedItemIds = [];

        foreach ($products as $p) {
            if (!is_array($p)) {
                continue;
            }

            $itemId = $p['item_id'] ?? ($p['itemId'] ?? null);
            if ($itemId === null || $itemId === '') {
                continue;
            }

            $skus = $p['skus'] ?? null;
            if (!is_array($skus)) {
                continue;
            }

            foreach ($skus as $s) {
                if (!is_array($s)) {
                    continue;
                }

                $sellerSku = $s['SellerSku'] ?? ($s['seller_sku'] ?? ($s['SellerSKU'] ?? null));
                if ($sellerSku === null) {
                    continue;
                }

                if (trim((string) $sellerSku) === $sku) {
                    $matchedItemIds[(string) $itemId] = true;
                    break;
                }
            }
        }

        $ids = array_keys($matchedItemIds);
        $count = count($ids);

        if ($count === 1) {
            return [$ids[0], 1];
        }

        return [null, $count];
    }

    public function findLazadaItemBySku(object $setting, LazadaClient $client, string $sku): array
    {
        $sku = trim($sku);
        if ($sku === '') return [null, 0];

        $result = $this->lazadaProductsGet($setting, $client, ['search' => $sku, 'limit' => '100', 'offset' => '0']);
        if (!empty($result)) {
            $matched = $this->matchSkuInProducts($result, $sku);
            if ($matched[1] > 0) return $matched;
        }

        $result = $this->lazadaProductsGet($setting, $client, ['sku_seller_list' => json_encode([$sku], JSON_UNESCAPED_SLASHES), 'limit' => '100', 'offset' => '0']);
        if (!empty($result)) {
            $matched = $this->matchSkuInProducts($result, $sku);
            if ($matched[1] > 0) return $matched;
        }

        $offset = 0;
        $limit = 50;
        $maxPages = 20;
        $page = 0;

        do {
            $products = $this->lazadaProductsGet($setting, $client, ['limit' => (string) $limit, 'offset' => (string) $offset]);
            if (empty($products)) break;

            $totalProducts = $products['_total'] ?? 0;

            $matched = $this->matchSkuInProducts($products, $sku);
            if ($matched[1] > 0) return $matched;

            $offset += $limit;
            $page++;
        } while ($offset < $totalProducts && $page < $maxPages);

        return [null, 0];
    }

    private function lazadaProductsGet(object $setting, LazadaClient $client, array $extra = []): array
    {
        $creds = LazadaSetting::activeCredentials($setting);

        $apiPath = '/products/get';
        $timestamp = (string) round(microtime(true) * 1000);
        $params = array_merge([
            'app_key' => (string) $creds['app_key'],
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
            'access_token' => (string) $creds['access_token'],
            'filter' => 'all',
        ], $extra);
        $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);

        $result = $client->get((string) $setting->region, $apiPath, $params);
        if (empty($result['ok'])) return [];

        $body = $result['body'] ?? [];
        $data = $body['data'] ?? [];
        return [
            'products' => $data['products'] ?? [],
            '_total' => (int) ($data['total_products'] ?? 0),
        ];
    }

    private function matchSkuInProducts(array $data, string $sku): array
    {
        $matchedItemIds = [];
        foreach ($data['products'] ?? [] as $p) {
            $itemId = $p['item_id'] ?? ($p['itemId'] ?? null);
            if ($itemId === null || $itemId === '') continue;

            foreach ($p['skus'] ?? [] as $s) {
                $sellerSku = $s['SellerSku'] ?? ($s['seller_sku'] ?? ($s['SellerSKU'] ?? null));
                if ($sellerSku !== null && strcasecmp(trim((string) $sellerSku), $sku) === 0) {
                    $matchedItemIds[(string) $itemId] = true;
                }
            }
        }

        $ids = array_keys($matchedItemIds);
        if (count($ids) === 1) return [$ids[0], 1];
        if (count($ids) > 1) return [null, count($ids)];
        return [null, 0];
    }

    public function fetchLazadaSkuMap(object $setting, LazadaClient $client): array
    {
        $creds = LazadaSetting::activeCredentials($setting);

        $skuMap = [];
        $offset = 0;
        $limit = 50;
        $maxPages = 60;
        $page = 0;

        do {
            $apiPath = '/products/get';
            $timestamp = (string) round(microtime(true) * 1000);
            $params = [
                'app_key' => (string) $creds['app_key'],
                'sign_method' => 'sha256',
                'timestamp' => $timestamp,
                'access_token' => (string) $creds['access_token'],
                'filter' => 'all',
                'offset' => (string) $offset,
                'limit' => (string) $limit,
            ];
            $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);

            $result = null;
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                if ($page > 0 || $attempt > 1) {
                    usleep(500000);
                }
                $timestamp = (string) round(microtime(true) * 1000);
                $params['timestamp'] = $timestamp;
                $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);
                $result = $client->get((string) $setting->region, $apiPath, $params);
                if (!empty($result['ok'])) break;
                Log::warning('Lazada fetchLazadaSkuMap API error', ['offset' => $offset, 'attempt' => $attempt, 'result' => $result['body'] ?? '']);
                if ($attempt < 3) usleep($attempt * 1000000);
            }

            if (empty($result['ok'])) {
                break;
            }

            $body = $result['body'] ?? [];
            $data = $body['data'] ?? [];
            $products = $data['products'] ?? [];
            $totalProducts = (int) ($data['total_products'] ?? 0);

            foreach ($products as $p) {
                $itemId = $p['item_id'] ?? ($p['itemId'] ?? null);
                if ($itemId === null || $itemId === '') continue;

                $skus = $p['skus'] ?? [];
                foreach ($skus as $s) {
                    $sellerSku = $s['SellerSku'] ?? ($s['seller_sku'] ?? ($s['SellerSKU'] ?? null));
                    if ($sellerSku === null) continue;
                    $sellerSku = trim((string) $sellerSku);
                    if ($sellerSku === '') continue;

                    $skuMap[$sellerSku][] = (string) $itemId;
                }
            }

            $offset += $limit;
            $page++;
        } while ($offset < $totalProducts && $page < $maxPages);

        foreach ($skuMap as $sku => $itemIds) {
            $skuMap[$sku] = array_values(array_unique($itemIds));
        }

        LazadaApiLog::safeCreate([
            'pack' => 'lazada.products.get.sku_map',
            'method' => 'GET',
            'api_path' => '/products/get',
            'auth_required' => true,
            'request_params' => ['pages_fetched' => $page, 'total_products' => $totalProducts ?? 0],
            'response_status' => 200,
            'ok' => true,
            'response_body' => ['skus_mapped' => count($skuMap)],
            'user_id' => auth()->id(),
        ]);

        return $skuMap;
    }
}
