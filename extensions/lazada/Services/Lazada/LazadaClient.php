<?php

namespace Extensions\lazada\Services\Lazada;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class LazadaClient
{
    /**
     * Lazada REST API endpoints by region.
     *
     * Sandbox and live use the SAME regional URL — the distinction lives in
     * which app_key/app_secret/access_token are presented in the request, not
     * in the host. Verified empirically 2026-05-16: sandbox app 114192's
     * credentials authenticated cleanly against api.lazada.com.ph/rest (HTTP
     * 200 from Lazada). The previous "if mode='sandbox' return api.lazada.test"
     * branch was a placeholder — .test is RFC 6761-reserved and never resolves
     * — which silently broke every sandbox API call once the rest of the
     * mode-aware plumbing started routing to it.
     *
     * The $mode parameter is preserved but no longer affects the URL.
     */
    public function baseUrl(?string $region, string $mode = 'live'): string
    {
        $region = strtolower(trim((string)$region));

        $map = [
            'ph' => 'https://api.lazada.com.ph/rest',
            'sg' => 'https://api.lazada.sg/rest',
            'my' => 'https://api.lazada.com.my/rest',
            'id' => 'https://api.lazada.co.id/rest',
            'th' => 'https://api.lazada.co.th/rest',
            'vn' => 'https://api.lazada.vn/rest',
        ];

        return $map[$region] ?? 'https://api.lazada.com/rest';
    }

    public function authUrl(string $appKey, string $redirectUri, ?string $state = null): string
    {
        $query = [
            'response_type' => 'code',
            'force_auth' => 'true',
            'redirect_uri' => $redirectUri,
            'client_id' => $appKey,
        ];
        if ($state !== null && $state !== '') {
            $query['state'] = $state;
        }

        return 'https://auth.lazada.com/oauth/authorize?' . http_build_query($query);
    }

    private function http(): PendingRequest
    {
        /* throw: false, same fix as ShopeeClient (2026-08-24). retry()
         * THROWS on the final non-2xx response by default, which made
         * every caller's `$result['ok'] ?? false` check unreachable for
         * HTTP-level failures: an expired access token was a 500 page,
         * not the handled error each call site already wrote. Connection
         * failures (no response at all) still throw; callers that must
         * stay up through those catch Throwable themselves. */
        return Http::timeout(30)
            ->retry(2, 300, throw: false);
    }

    /**
     * Lazada signature algorithm:
     * - Sort all parameters by key (ASCII), excluding 'sign'
     * - Concatenate key + value with no separators
     * - Prepend API path
     * - HMAC_SHA256 using app secret, HEX uppercase
     */
    public function sign(string $apiPath, array $params, string $appSecret): string
    {
        unset($params['sign']);

        ksort($params);

        $base = $apiPath;
        foreach ($params as $k => $v) {
            if ($v === null) {
                continue;
            }
            $base .= (string)$k . (string)$v;
        }

        $secret = trim($appSecret);
        return strtoupper(hash_hmac('sha256', $base, $secret));
    }

    public function post(string $region, string $apiPath, array $params, string $mode = 'live'): array
    {
        $url = $this->baseUrl($region, $mode) . $apiPath;

        // Lazada accepts GET/POST; POST as form is safest and matches most SDKs.
        $resp = $this->http()
            ->asForm()
            ->post($url, $params);

        return [
            'status' => $resp->status(),
            'ok' => $resp->ok(),
            'body' => $resp->json() ?? $resp->body(),
        ];
    }

    public function get(string $region, string $apiPath, array $params, string $mode = 'live'): array
    {
        $url = $this->baseUrl($region, $mode) . $apiPath;

        $resp = $this->http()
            ->get($url, $params);

        return [
            'status' => $resp->status(),
            'ok' => $resp->ok(),
            'body' => $resp->json() ?? $resp->body(),
        ];
    }
}
