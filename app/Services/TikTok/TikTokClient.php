<?php

namespace App\Services\TikTok;

use Illuminate\Support\Facades\Http;

class TikTokClient
{
    private const BASE_URL = 'https://open-api.tiktokglobalshop.com';
    private const AUTH_HOST = 'https://auth.tiktok-shops.com';

    public function baseUrl(): string
    {
        return self::BASE_URL;
    }

    // ── Auth URL ─────────────────────────────────────────────────

    public function authUrl(string $appKey, ?string $state = null): string
    {
        $state = $state ?: (string) random_int(100000, 999999);

        return self::AUTH_HOST . '/oauth/authorize?' . http_build_query([
            'app_key' => $appKey,
            'state' => $state,
        ]);
    }

    // ── Token Exchange ───────────────────────────────────────────

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

    // ── Token Refresh ────────────────────────────────────────────

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

    // ── Signature Generation ─────────────────────────────────────

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

    // ── Signed GET ───────────────────────────────────────────────

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

    // ── Signed POST ──────────────────────────────────────────────

    public function post(string $appKey, string $appSecret, string $accessToken, string $path, array $queryParams = [], array $body = [], ?string $shopCipher = null): array
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
            ->post($url);

        return [
            'status' => $response->status(),
            'ok' => $response->ok(),
            'body' => $response->json() ?? $response->body(),
        ];
    }
}
