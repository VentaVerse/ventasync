<?php

namespace Extensions\tiktok\Services\TikTok;

use Illuminate\Support\Facades\Http;

class TikTokClient
{
    private const BASE_URL = 'https://open-api.tiktokglobalshop.com';
    private const AUTH_HOST = 'https://auth.tiktok-shops.com';

    public function baseUrl(): string
    {
        return self::BASE_URL;
    }

    // -- Auth URL ---------------------------------------------------------

    public function authUrl(string $appKey, ?string $state = null): string
    {
        $state = $state ?: (string) random_int(100000, 999999);

        return self::AUTH_HOST . '/oauth/authorize?' . http_build_query([
            'app_key' => $appKey,
            'state' => $state,
        ]);
    }

    // -- Token Exchange ---------------------------------------------------

    public function getToken(string $appKey, string $appSecret, string $authCode): array
    {
        $url = self::AUTH_HOST . '/api/v2/token/get';

        $response = Http::timeout(30)->get($url, [
            'app_key' => $appKey,
            'app_secret' => $appSecret,
            'auth_code' => $authCode,
            'grant_type' => 'authorized_code',
        ]);

        return [
            'status' => $response->status(),
            'ok' => $response->ok(),
            'body' => $response->json() ?? $response->body(),
        ];
    }

    // -- Token Refresh ----------------------------------------------------

    public function refreshToken(string $appKey, string $appSecret, string $refreshToken): array
    {
        $url = self::AUTH_HOST . '/api/v2/token/refresh';

        $response = Http::timeout(30)->get($url, [
            'app_key' => $appKey,
            'app_secret' => $appSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        return [
            'status' => $response->status(),
            'ok' => $response->ok(),
            'body' => $response->json() ?? $response->body(),
        ];
    }

    // -- Signature Generation ---------------------------------------------

    public function generateSign(string $path, array $params, ?string $body, string $appSecret): string
    {
        // Remove excluded keys
        unset($params['sign'], $params['access_token']);

        // Sort remaining params alphabetically by key
        ksort($params);

        // Build sign string: app_secret + path + key1value1key2value2... + body + app_secret
        $signString = $appSecret . $path;
        foreach ($params as $k => $v) {
            if ($v === null) continue;
            $signString .= (string) $k . (string) $v;
        }
        if ($body !== null && $body !== '') {
            $signString .= $body;
        }
        $signString .= $appSecret;

        return hash_hmac('sha256', $signString, $appSecret);
    }

    // -- Signed GET -------------------------------------------------------

    public function get(string $appKey, string $appSecret, string $accessToken, string $path, array $extraParams = [], ?string $shopCipher = null): array
    {
        $params = array_merge($extraParams, [
            'app_key' => $appKey,
            'timestamp' => time(),
        ]);
        if ($shopCipher) {
            $params['shop_cipher'] = $shopCipher;
        }

        $params['sign'] = $this->generateSign($path, $params, null, $appSecret);

        $url = self::BASE_URL . $path;

        $response = Http::timeout(30)
            ->withHeaders(['x-tts-access-token' => $accessToken])
            ->get($url, $params);

        return [
            'status' => $response->status(),
            'ok' => $response->ok(),
            'body' => $response->json() ?? $response->body(),
        ];
    }

    // -- Signed POST ------------------------------------------------------

    public function post(string $appKey, string $appSecret, string $accessToken, string $path, array $queryParams = [], array $body = [], ?string $shopCipher = null): array
    {
        $params = array_merge($queryParams, [
            'app_key' => $appKey,
            'timestamp' => time(),
        ]);
        if ($shopCipher) {
            $params['shop_cipher'] = $shopCipher;
        }

        $jsonBody = !empty($body) ? json_encode($body) : '{}';
        $params['sign'] = $this->generateSign($path, $params, $jsonBody, $appSecret);

        $url = self::BASE_URL . $path . '?' . http_build_query($params);

        $response = Http::timeout(30)
            ->withHeaders([
                'x-tts-access-token' => $accessToken,
                'Content-Type' => 'application/json',
            ])
            ->withBody($jsonBody, 'application/json')
            ->post($url);

        return [
            'status' => $response->status(),
            'ok' => $response->ok(),
            'body' => $response->json() ?? $response->body(),
        ];
    }

    // -- Signed PUT -------------------------------------------------------

    public function put(string $appKey, string $appSecret, string $accessToken, string $path, array $queryParams = [], array $body = [], ?string $shopCipher = null): array
    {
        $params = array_merge($queryParams, [
            'app_key' => $appKey,
            'timestamp' => time(),
        ]);
        if ($shopCipher) {
            $params['shop_cipher'] = $shopCipher;
        }

        $jsonBody = !empty($body) ? json_encode($body) : null;
        $params['sign'] = $this->generateSign($path, $params, $jsonBody, $appSecret);

        $url = self::BASE_URL . $path . '?' . http_build_query($params);

        $response = Http::timeout(30)
            ->withHeaders([
                'x-tts-access-token' => $accessToken,
                'Content-Type' => 'application/json',
            ])
            ->withBody($jsonBody ?? '{}', 'application/json')
            ->put($url);

        return [
            'status' => $response->status(),
            'ok' => $response->ok(),
            'body' => $response->json() ?? $response->body(),
        ];
    }

    // -- Categories -------------------------------------------------------

    public function getCategories(string $appKey, string $appSecret, string $accessToken, ?string $shopCipher = null): array
    {
        return $this->get($appKey, $appSecret, $accessToken, '/product/202309/categories', ['category_version' => 'v2'], $shopCipher);
    }

    public function getCategoryAttributes(string $appKey, string $appSecret, string $accessToken, string $categoryId, ?string $shopCipher = null): array
    {
        return $this->get($appKey, $appSecret, $accessToken, '/product/202309/categories/' . $categoryId . '/attributes', [], $shopCipher);
    }

    public function recommendCategory(string $appKey, string $appSecret, string $accessToken, string $title, ?string $description = null, ?string $shopCipher = null): array
    {
        $body = ['product_title' => $title];
        if ($description) {
            $body['description'] = $description;
        }
        return $this->post($appKey, $appSecret, $accessToken, '/product/202309/categories/recommend', [], $body, $shopCipher);
    }

    // -- Products ---------------------------------------------------------

    public function searchProducts(string $appKey, string $appSecret, string $accessToken, int $pageSize = 20, ?string $pageToken = null, ?string $shopCipher = null): array
    {
        $body = ['page_size' => $pageSize];
        if ($pageToken) $body['page_token'] = $pageToken;
        return $this->post($appKey, $appSecret, $accessToken, '/product/202309/products/search', [], $body, $shopCipher);
    }

    public function getProduct(string $appKey, string $appSecret, string $accessToken, string $productId, ?string $shopCipher = null): array
    {
        return $this->get($appKey, $appSecret, $accessToken, '/product/202309/products/' . $productId, [], $shopCipher);
    }

    public function createProduct(string $appKey, string $appSecret, string $accessToken, array $data, ?string $shopCipher = null): array
    {
        return $this->post($appKey, $appSecret, $accessToken, '/product/202309/products', [], $data, $shopCipher);
    }

    public function editProduct(string $appKey, string $appSecret, string $accessToken, string $productId, array $data, ?string $shopCipher = null): array
    {
        return $this->put($appKey, $appSecret, $accessToken, '/product/202309/products/' . $productId, [], $data, $shopCipher);
    }

    // -- Signed DELETE ----------------------------------------------------

    public function delete(string $appKey, string $appSecret, string $accessToken, string $path, array $queryParams = [], array $body = [], ?string $shopCipher = null): array
    {
        $params = array_merge($queryParams, [
            'app_key' => $appKey,
            'timestamp' => time(),
        ]);
        if ($shopCipher) {
            $params['shop_cipher'] = $shopCipher;
        }

        $jsonBody = !empty($body) ? json_encode($body) : null;
        $params['sign'] = $this->generateSign($path, $params, $jsonBody, $appSecret);

        $url = self::BASE_URL . $path . '?' . http_build_query($params);

        $response = Http::timeout(30)
            ->withHeaders([
                'x-tts-access-token' => $accessToken,
                'Content-Type' => 'application/json',
            ])
            ->withBody($jsonBody ?? '{}', 'application/json')
            ->delete($url);

        return [
            'status' => $response->status(),
            'ok' => $response->ok(),
            'body' => $response->json() ?? $response->body(),
        ];
    }

    public function deleteProducts(string $appKey, string $appSecret, string $accessToken, array $productIds, ?string $shopCipher = null): array
    {
        return $this->delete($appKey, $appSecret, $accessToken, '/product/202309/products', [], ['product_ids' => $productIds], $shopCipher);
    }

    // -- Price & Stock ----------------------------------------------------

    public function updatePrice(string $appKey, string $appSecret, string $accessToken, string $productId, array $skus, ?string $shopCipher = null): array
    {
        return $this->post($appKey, $appSecret, $accessToken, '/product/202309/products/' . $productId . '/prices/update', [], ['skus' => $skus], $shopCipher);
    }

    public function updateInventory(string $appKey, string $appSecret, string $accessToken, string $productId, array $skus, ?string $shopCipher = null): array
    {
        return $this->post($appKey, $appSecret, $accessToken, '/product/202309/products/' . $productId . '/inventory/update', [], ['skus' => $skus], $shopCipher);
    }

    // -- Orders -----------------------------------------------------------

    public function searchOrders(string $appKey, string $appSecret, string $accessToken, int $pageSize, array $body = [], ?string $shopCipher = null): array
    {
        // page_size is a query param; dates (create_time_ge, create_time_lt, next_page_token) are POST body
        return $this->post($appKey, $appSecret, $accessToken, '/order/202309/orders/search', ['page_size' => $pageSize], $body, $shopCipher);
    }

    public function getOrderDetail(string $appKey, string $appSecret, string $accessToken, array $orderIds, ?string $shopCipher = null): array
    {
        return $this->post($appKey, $appSecret, $accessToken, '/order/202309/orders', [], ['order_ids' => $orderIds], $shopCipher);
    }

    // -- Finance ----------------------------------------------------------

    public function getOrderStatementTransactions(string $appKey, string $appSecret, string $accessToken, string $orderId, ?string $shopCipher = null): array
    {
        return $this->get($appKey, $appSecret, $accessToken, '/finance/202309/orders/' . $orderId . '/statement_transactions', [], $shopCipher);
    }

    // -- Fulfillment ------------------------------------------------------

    public function shipPackage(string $appKey, string $appSecret, string $accessToken, string $orderId, array $packageData, ?string $shopCipher = null): array
    {
        $body = array_merge(['order_id' => $orderId], $packageData);
        return $this->post($appKey, $appSecret, $accessToken, '/fulfillment/202309/packages/ship', [], $body, $shopCipher);
    }

    public function getShippingDocument(string $appKey, string $appSecret, string $accessToken, string $packageId, string $documentType = 'SHIPPING_LABEL', ?string $shopCipher = null): array
    {
        return $this->get($appKey, $appSecret, $accessToken, '/fulfillment/202309/packages/' . $packageId . '/shipping_documents', [
            'document_type' => $documentType,
            'document_size' => 'A6',
        ], $shopCipher);
    }

    public function getOrderTracking(string $appKey, string $appSecret, string $accessToken, string $orderId, ?string $shopCipher = null): array
    {
        return $this->get($appKey, $appSecret, $accessToken, '/fulfillment/202309/orders/' . $orderId . '/tracking', [], $shopCipher);
    }

    // -- Images -----------------------------------------------------------

    public function uploadImage(string $appKey, string $appSecret, string $accessToken, string $imageData, ?string $shopCipher = null): array
    {
        $params = [
            'app_key' => $appKey,
            'timestamp' => time(),
        ];
        if ($shopCipher) {
            $params['shop_cipher'] = $shopCipher;
        }

        // Image upload uses multipart — sign without body
        $params['sign'] = $this->generateSign('/product/202309/images/upload', $params, null, $appSecret);

        $url = self::BASE_URL . '/product/202309/images/upload?' . http_build_query($params);

        $response = Http::timeout(60)
            ->withHeaders(['x-tts-access-token' => $accessToken])
            ->attach('data', $imageData, 'image.jpg')
            ->post($url);

        return [
            'status' => $response->status(),
            'ok' => $response->ok(),
            'body' => $response->json() ?? $response->body(),
        ];
    }
}
