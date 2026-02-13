<?php

namespace App\Http\Controllers;

use App\Models\LazadaSetting;
use App\Services\Lazada\LazadaClient;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LazadaController extends Controller
{
    public function index()
    {
        $setting = LazadaSetting::query()->first();

        return view('lazada.index', [
            'setting' => $this->decryptSetting($setting),
            'defaultRedirect' => route('lazada.callback'),
            'result' => session('lazada_result'),
        ]);
    }

    public function save(Request $request)
    {
        $data = $request->validate([
            'region' => 'nullable|in:ph,sg,my,id,th,vn',
            'app_key' => 'nullable|string|max:64',
            'app_secret' => 'nullable|string|max:255',
            'redirect_uri' => 'nullable|string|max:255',
        ]);

        $setting = LazadaSetting::query()->first() ?? new LazadaSetting();

        $setting->region = $data['region'] ?? null;
        $setting->app_key = $data['app_key'] ?? null;
        $setting->redirect_uri = $data['redirect_uri'] ?? null;

        // Encrypt secret at rest
        $setting->app_secret = isset($data['app_secret']) && $data['app_secret'] !== ''
            ? encrypt(trim($data['app_secret']))
            : null;

        $setting->save();

        return redirect()->route('lazada.index')->with('lazada_result', [
            'ok' => true,
            'title' => 'Saved',
            'data' => ['message' => 'Lazada settings saved.'],
        ]);
    }

    public function redirectToAuth(LazadaClient $client)
    {
        $setting = $this->decryptSetting(LazadaSetting::query()->first());

        if (!$setting || !$setting->app_key || !$setting->redirect_uri) {
            return redirect()->route('lazada.index')->with('lazada_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => 'App Key and Redirect URI are required to authorize.'],
            ]);
        }

        // Stateless POC, but we still bind a state token to reduce accidental cross-account redirects.
        $state = Str::random(24);
        session(['lazada_oauth_state' => $state]);

        $url = $client->authUrl((string)$setting->app_key, (string)$setting->redirect_uri, $state);

        return redirect()->away($url);
    }

    public function callback(Request $request, LazadaClient $client)
    {
        $code = (string)$request->query('code', '');
        $state = (string)$request->query('state', '');

        // If user hit callback without code, show the page (useful for debugging)
        if ($code === '') {
            return view('lazada.callback', [
                'code' => null,
            ]);
        }

        // Optional state check (non-blocking in POC to avoid bricking prod behind proxies)
        $expected = (string)session('lazada_oauth_state');
        if ($expected !== '' && $state !== '' && !hash_equals($expected, $state)) {
            return redirect()->route('lazada.index')->with('lazada_result', [
                'ok' => false,
                'title' => 'OAuth state mismatch',
                'data' => ['message' => 'State mismatch. Please re-authorize from the Lazada page.'],
            ]);
        }

        // Auto token exchange to complete the POC.
        $setting = $this->decryptSetting(LazadaSetting::query()->first());
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret) {
            return redirect()->route('lazada.index')->with('lazada_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => 'Region, App Key, and App Secret are required to exchange tokens.'],
            ]);
        }

        $result = $this->doTokenCreate($client, (string)$setting->region, (string)$setting->app_key, (string)$setting->app_secret, $code);

        if ($result['ok'] && is_array($result['body'])) {
            $access = $result['body']['access_token'] ?? null;
            $refresh = $result['body']['refresh_token'] ?? null;
            $expiresIn = $result['body']['expires_in'] ?? null;
            $refreshExpiresIn = $result['body']['refresh_expires_in'] ?? null;

            $raw = LazadaSetting::query()->first() ?? new LazadaSetting();

            if ($access) {
                $raw->access_token = encrypt((string)$access);
            }
            if ($refresh) {
                $raw->refresh_token = encrypt((string)$refresh);
            }
            if (is_numeric($expiresIn)) {
                $raw->expires_at = now()->addSeconds((int)$expiresIn);
            }
            if (is_numeric($refreshExpiresIn)) {
                $raw->refresh_expires_at = now()->addSeconds((int)$refreshExpiresIn);
            }

            $raw->account = $result['body']['account'] ?? $raw->account;
            $raw->country = $result['body']['country'] ?? $raw->country;

            $raw->save();
        }

        return redirect()->route('lazada.index')->with('lazada_result', [
            'ok' => (bool)$result['ok'],
            'title' => 'OAuth Callback / Token Create',
            'data' => $result,
        ]);
    }

    public function tokenCreate(Request $request, LazadaClient $client)
    {
        $request->validate([
            'code' => 'required|string',
        ]);

        $setting = $this->decryptSetting(LazadaSetting::query()->first());
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret) {
            return redirect()->route('lazada.index')->with('lazada_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => 'Region, App Key, and App Secret are required.'],
            ]);
        }

        $result = $this->doTokenCreate($client, (string)$setting->region, (string)$setting->app_key, (string)$setting->app_secret, (string)$request->input('code'));

        return redirect()->route('lazada.index')->with('lazada_result', [
            'ok' => (bool)$result['ok'],
            'title' => 'Token Create',
            'data' => $result,
        ]);
    }

    public function tokenRefresh(LazadaClient $client)
    {
        $setting = $this->decryptSetting(LazadaSetting::query()->first());
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret || !$setting->refresh_token) {
            return redirect()->route('lazada.index')->with('lazada_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => 'Region, App Key, App Secret, and Refresh Token are required.'],
            ]);
        }

        $apiPath = '/auth/token/refresh';
        $timestamp = (string)round(microtime(true) * 1000);

        $params = [
            'app_key' => (string)$setting->app_key,
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
            'refresh_token' => (string)$setting->refresh_token,
            'grant_type' => 'refresh_token',
        ];

        $params['sign'] = $client->sign($apiPath, $params, (string)$setting->app_secret);

        $result = $client->post((string)$setting->region, $apiPath, $params);

        if ($result['ok'] && is_array($result['body'])) {
            $access = $result['body']['access_token'] ?? null;
            $expiresIn = $result['body']['expires_in'] ?? null;

            $raw = LazadaSetting::query()->first();
            if ($raw && $access) {
                $raw->access_token = encrypt((string)$access);
                if (is_numeric($expiresIn)) {
                    $raw->expires_at = now()->addSeconds((int)$expiresIn);
                }
                $raw->save();
            }
        }

        return redirect()->route('lazada.index')->with('lazada_result', [
            'ok' => (bool)$result['ok'],
            'title' => 'Token Refresh',
            'data' => $result,
        ]);
    }

    public function callApi(Request $request, LazadaClient $client)
    {
        $request->validate([
            'api_path' => 'required|string',
        ]);

        $setting = $this->decryptSetting(LazadaSetting::query()->first());
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret || !$setting->access_token) {
            return redirect()->route('lazada.index')->with('lazada_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => 'Region, App Key, App Secret, and Access Token are required to call API.'],
            ]);
        }

        $apiPath = (string)$request->input('api_path');
        $timestamp = (string)round(microtime(true) * 1000);

        $params = [
            'app_key' => (string)$setting->app_key,
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
            'access_token' => (string)$setting->access_token,
        ];

        $params['sign'] = $client->sign($apiPath, $params, (string)$setting->app_secret);

        $result = $client->get((string)$setting->region, $apiPath, $params);

        return redirect()->route('lazada.index')->with('lazada_result', [
            'ok' => (bool)$result['ok'],
            'title' => 'API Call',
            'data' => $result,
        ]);
    }

    private function doTokenCreate(LazadaClient $client, string $region, string $appKey, string $appSecret, string $code): array
    {
        $apiPath = '/auth/token/create';
        $timestamp = (string)round(microtime(true) * 1000);

        $params = [
            'app_key' => trim($appKey),
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
            'code' => trim($code),
            'grant_type' => 'authorization_code',
        ];

        $params['sign'] = $client->sign($apiPath, $params, trim($appSecret));

        return $client->post($region, $apiPath, $params);
    }

    private function decryptSetting(?LazadaSetting $setting): ?object
    {
        if (!$setting) {
            return null;
        }

        $s = (object)$setting->toArray();

        foreach (['app_secret', 'access_token', 'refresh_token'] as $k) {
            if (!empty($s->$k)) {
                try {
                    $s->$k = decrypt($s->$k);
                } catch (\Throwable $e) {
                    // Treat as absent rather than breaking prod pages
                    $s->$k = null;
                }
            }
        }

        return $s;
    }
}
