<?php

namespace Extensions\shopee\Services\Shopee;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class ShopeeClient
{
    public function baseUrl(string $mode): string
    {
        // sandbox: partner.test-stable.shopeemobile.com
        // live: partner.shopeemobile.com
        return $mode === 'live'
            ? 'https://partner.shopeemobile.com'
            : 'https://partner.test-stable.shopeemobile.com';
    }

    private function http(): PendingRequest
    {
        return Http::timeout(30)
            ->retry(2, 300)
            ->acceptJson()
            ->asJson();
    }

    private function hmac(string $base, string $partnerKey): string
    {
        // partner_key coming from UI / dashboard often includes accidental whitespace/newlines
        $key = trim($partnerKey);
        return hash_hmac('sha256', $base, $key);
    }

    /** Default auth signature: partner_id + path + timestamp */
    public function signAuth(int $partnerId, string $partnerKey, string $path, int $timestamp): string
    {
        $base = (string)$partnerId . $path . (string)$timestamp;
        return $this->hmac($base, $partnerKey);
    }

    /** Shop-level signature: partner_id + path + timestamp + access_token + shop_id */
    public function signShop(
        int $partnerId,
        string $partnerKey,
        string $path,
        int $timestamp,
        string $accessToken,
        int $shopId
    ): string {
        $base = (string)$partnerId . $path . (string)$timestamp . $accessToken . (string)$shopId;
        return $this->hmac($base, $partnerKey);
    }

    /**
     * Token exchange signature differs across Shopee docs/regions.
     * We attempt the strictest variants in a single production-safe fallback sequence:
     *  1) partner_id + path + timestamp
     *  2) partner_id + path + timestamp + shop_id
     */
    public function exchangeToken(string $mode, int $partnerId, string $partnerKey, string $code, int $shopId): array
    {
        $path = '/api/v2/auth/token/get';
        $timestamp = time();

        $code = trim($code);

        $variants = [
            // variant A: docs commonly show this for auth
            $this->signAuth($partnerId, $partnerKey, $path, $timestamp),
            // variant B: some integrations require shop_id included
            $this->hmac((string)$partnerId . $path . (string)$timestamp . (string)$shopId, $partnerKey),
        ];

        $body = [
            'code' => $code,
            'shop_id' => (int)$shopId,
            'partner_id' => (int)$partnerId,
        ];

        foreach ($variants as $idx => $sign) {
            $query = [
                'partner_id' => (int)$partnerId,
                'timestamp' => $timestamp,
                'sign' => $sign,
            ];

            $res = $this->postJson($mode, $path, $query, $body);

            // Success, or a non-signature error that shouldn't be retried with different signature
            if ($res['ok']) {
                $res['meta'] = ['sign_variant' => $idx === 0 ? 'auth' : 'auth+shop_id'];
                return $res;
            }

            if (!is_array($res['body']) || (($res['body']['error'] ?? null) !== 'error_sign')) {
                $res['meta'] = ['sign_variant' => $idx === 0 ? 'auth' : 'auth+shop_id'];
                return $res;
            }

            // error_sign: try next variant (only once)
        }

        // If all failed, return last response
        $res['meta'] = ['sign_variant' => 'all_failed'];
        return $res;
    }

    public function buildAuthUrl(string $mode, int $partnerId, string $partnerKey, string $redirectUri): string
    {
        $path = '/api/v2/shop/auth_partner';
        $timestamp = time();
        $sign = $this->signAuth($partnerId, $partnerKey, $path, $timestamp);

        return $this->baseUrl($mode) . $path
            . '?partner_id=' . urlencode((string)$partnerId)
            . '&timestamp=' . urlencode((string)$timestamp)
            . '&sign=' . urlencode($sign)
            . '&redirect=' . urlencode($redirectUri);
    }

    public function postJson(string $mode, string $path, array $query, array $body): array
    {
        $url = $this->baseUrl($mode) . $path;

        $resp = $this->http()
            ->post($url . '?' . http_build_query($query), $body);

        return [
            'status' => $resp->status(),
            'ok' => $resp->ok(),
            'body' => $resp->json() ?? $resp->body(),
        ];
    }

    public function get(string $mode, string $path, array $query): array
    {
        $url = $this->baseUrl($mode) . $path;

        $resp = $this->http()
            ->get($url . '?' . http_build_query($query));

        return [
            'status' => $resp->status(),
            'ok' => $resp->ok(),
            'body' => $resp->json() ?? $resp->body(),
        ];
    }

    /**
     * Convenience: shop-level signed GET request.
     */
    public function shopGet(
        string $mode,
        int $partnerId,
        string $partnerKey,
        string $accessToken,
        int $shopId,
        string $path,
        array $extraQuery = []
    ): array {
        $timestamp = time();
        $sign = $this->signShop($partnerId, $partnerKey, $path, $timestamp, $accessToken, $shopId);

        $query = array_merge([
            'partner_id'   => $partnerId,
            'timestamp'    => $timestamp,
            'sign'         => $sign,
            'access_token' => $accessToken,
            'shop_id'      => $shopId,
        ], $extraQuery);

        return $this->get($mode, $path, $query);
    }

    /**
     * Convenience: shop-level signed POST request.
     */
    public function shopPost(
        string $mode,
        int $partnerId,
        string $partnerKey,
        string $accessToken,
        int $shopId,
        string $path,
        array $extraQuery = [],
        array $body = []
    ): array {
        $timestamp = time();
        $sign = $this->signShop($partnerId, $partnerKey, $path, $timestamp, $accessToken, $shopId);

        $query = array_merge([
            'partner_id'   => $partnerId,
            'timestamp'    => $timestamp,
            'sign'         => $sign,
            'access_token' => $accessToken,
            'shop_id'      => $shopId,
        ], $extraQuery);

        return $this->postJson($mode, $path, $query, $body);
    }
}
