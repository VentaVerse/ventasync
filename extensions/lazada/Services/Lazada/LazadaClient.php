<?php

namespace Extensions\lazada\Services\Lazada;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class LazadaClient
{
    // Sandbox and live share the same regional host; only the credentials differ.
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
        return Http::connectTimeout(10)
            ->timeout(20)
            ->retry(2, 300, throw: false);
    }

    // Sign: sort params by key, concatenate key+value, prepend the API path, HMAC-SHA256 uppercase hex.
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
