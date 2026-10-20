<?php

namespace Extensions\Lazada\Services\Lazada;

use Extensions\Lazada\Models\LazadaApiLog;
use Extensions\Lazada\Models\LazadaStore;
use Illuminate\Support\Facades\Log;

class LazadaItemMatcher
{
    public function fetchLazadaSkuMap(object $store, LazadaClient $client): array
    {
        $creds = LazadaStore::activeCredentials($store);

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
                $result = $client->get((string) $store->region, $apiPath, $params);
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
