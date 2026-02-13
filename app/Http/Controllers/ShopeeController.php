<?php

namespace App\Http\Controllers;

use App\Models\ShopeeSetting;
use App\Services\Shopee\ShopeeClient;
use Illuminate\Http\Request;

class ShopeeController extends Controller
{
    
public function root(Request $request, ShopeeClient $client)
{
    // Shopee may only allow a domain-level redirect URI (e.g. https://shop-a.example)
    // When redirected here with ?code=...&shop_id=..., we exchange tokens immediately and persist them.

    $code = $request->query('code');
    $shopId = $request->query('shop_id');

    if (!$code && !$shopId) {
        return redirect()->route('dashboard');
    }

    // Persist shop_id if present
    if ($shopId) {
        $raw = ShopeeSetting::query()->first() ?? new ShopeeSetting();
        $raw->shop_id = (int)$shopId;
        $raw->save();
    }

    $setting = $this->decryptSetting(ShopeeSetting::query()->first());

    // If we have everything required, perform token exchange automatically
    if ($code && $setting && $setting->partner_id && $setting->partner_key && $setting->shop_id) {
        $result = $client->exchangeToken(
            $setting->mode ?? 'sandbox',
            (int)$setting->partner_id,
            (string)$setting->partner_key,
            (string)$code,
            (int)$setting->shop_id
        );

        // Persist tokens if present
        if ($result['ok'] && is_array($result['body'])) {
            $access = $result['body']['access_token'] ?? null;
            $refresh = $result['body']['refresh_token'] ?? null;

            if ($access) {
                $raw = ShopeeSetting::query()->first();
                if ($raw) {
                    $raw->access_token = encrypt($access);
                    if ($refresh) {
                        $raw->refresh_token = encrypt($refresh);
                    }
                    $raw->save();
                }
            }
        }

        return redirect()->route('shopee.index')->with('shopee_result', [
            'ok' => (bool)$result['ok'],
            'title' => 'OAuth Callback (Domain Redirect)',
            'data' => $result,
        ]);
    }

    // Fallback: show code + shop_id so user can complete token exchange manually in the Shopee page
    return view('shopee.callback', [
        'code' => $code,
        'shop_id' => $shopId,
    ]);
}

public function index()
    {
        $setting = ShopeeSetting::query()->first();

        return view('shopee.index', [
            'setting' => $this->decryptSetting($setting),
            'defaultRedirect' => 'https://shop-a.example',
            'result' => session('shopee_result'),
        ]);
    }

    public function save(Request $request)
    {
        $data = $request->validate([
            'mode' => 'required|in:sandbox,live',
            'partner_id' => 'nullable|integer|min:1',
            'partner_key' => 'nullable|string',
            'shop_id' => 'nullable|integer|min:1',
            'access_token' => 'nullable|string',
            'redirect_uri' => 'nullable|string|max:255',
            'region' => 'nullable|string|max:16',
        ]);

        $setting = ShopeeSetting::query()->first() ?? new ShopeeSetting();

        $setting->mode = $data['mode'];
        $setting->partner_id = $data['partner_id'] ?? null;
        $setting->shop_id = $data['shop_id'] ?? null;
        $setting->redirect_uri = $data['redirect_uri'] ?? null;
        $setting->region = $data['region'] ?? null;

        // Encrypt secrets at rest
        $setting->partner_key = isset($data['partner_key']) && $data['partner_key'] !== ''
            ? encrypt(trim($data['partner_key']))
            : null;

        $setting->access_token = isset($data['access_token']) && $data['access_token'] !== ''
            ? encrypt(trim($data['access_token']))
            : null;
        // Refresh token is managed by OAuth token exchange; do not overwrite from settings UI.

        $setting->save();

        return redirect()->route('shopee.index')->with('shopee_result', [
            'ok' => true,
            'title' => 'Saved',
            'data' => ['message' => 'Shopee settings saved.'],
        ]);
    }

    public function callback(Request $request)
    {
        // Shopee returns code + shop_id (and possibly main_account_id for some setups)
        $code = $request->query('code');
        $shopId = $request->query('shop_id');

        // Store shop_id if present (safe), keep code only for immediate token exchange
        if ($shopId) {
            $setting = ShopeeSetting::query()->first() ?? new ShopeeSetting();
            $setting->shop_id = (int)$shopId;
            $setting->save();
        }

        return view('shopee.callback', [
            'code' => $code,
            'shop_id' => $shopId,
        ]);
    }

    public function redirectToShopeeAuth(ShopeeClient $client)
{
    $setting = $this->decryptSetting(ShopeeSetting::query()->first());

    if (!$setting || !$setting->partner_id || !$setting->partner_key) {
        return redirect()->route('shopee.index')->with('shopee_result', [
            'ok' => false,
            'title' => 'Missing settings',
            'data' => ['message' => 'Partner ID and Partner Key are required to authorize.'],
        ]);
    }

    $redirect = $setting->redirect_uri ?: 'https://shop-a.example';
    $url = $client->buildAuthUrl($setting->mode, (int)$setting->partner_id, (string)$setting->partner_key, $redirect);

    return redirect()->away($url);
}

public function buildAuthUrl(ShopeeClient $client)
    {
        $setting = $this->decryptSetting(ShopeeSetting::query()->first());

        if (!$setting || !$setting->partner_id || !$setting->partner_key) {
            return redirect()->route('shopee.index')->with('shopee_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => 'Partner ID and Partner Key are required to build the auth URL.'],
            ]);
        }

        $redirect = $setting->redirect_uri ?: 'https://shop-a.example';

        $url = $client->buildAuthUrl($setting->mode, (int)$setting->partner_id, (string)$setting->partner_key, $redirect);

        return redirect()->route('shopee.index')->with('shopee_result', [
            'ok' => true,
            'title' => 'Auth URL generated',
            'data' => ['auth_url' => $url],
        ]);
    }

    public function tokenGet(Request $request, ShopeeClient $client)
    {
        $request->validate([
            'code' => 'required|string',
        ]);

        $setting = $this->decryptSetting(ShopeeSetting::query()->first());
        if (!$setting || !$setting->partner_id || !$setting->partner_key || !$setting->shop_id) {
            return redirect()->route('shopee.index')->with('shopee_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => 'Partner ID, Partner Key, and Shop ID are required.'],
            ]);
        }

        $result = $client->exchangeToken(
            $setting->mode ?? 'sandbox',
            (int)$setting->partner_id,
            (string)$setting->partner_key,
            (string)$code,
            (int)$setting->shop_id
        );

        // Persist tokens if present
        if ($result['ok'] && is_array($result['body'])) {
            $access = $result['body']['access_token'] ?? null;
            $refresh = $result['body']['refresh_token'] ?? null;

            if ($access) {
                $raw = ShopeeSetting::query()->first();
                if ($raw) {
                    $raw->access_token = encrypt($access);
                    if ($refresh) {
                        $raw->refresh_token = encrypt($refresh);
                    }
                    $raw->save();
                }
            }
        }

        return redirect()->route('shopee.index')->with('shopee_result', [
            'ok' => (bool)$result['ok'],
            'title' => 'Token Get',
            'data' => $result,
        ]);
    }

    public function tokenRefresh(ShopeeClient $client)
    {
        $setting = $this->decryptSetting(ShopeeSetting::query()->first());
        if (!$setting || !$setting->partner_id || !$setting->partner_key || !$setting->shop_id || !$setting->refresh_token) {
            return redirect()->route('shopee.index')->with('shopee_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => 'Partner ID, Partner Key, Shop ID, and Refresh Token are required.'],
            ]);
        }

        $path = '/api/v2/auth/access_token/get';
        $timestamp = time();
        $sign = $client->sign((int)$setting->partner_id, (string)$setting->partner_key, $path, $timestamp);

        $query = [
            'partner_id' => (int)$setting->partner_id,
            'timestamp' => $timestamp,
            'sign' => $sign,
        ];

        $body = [
            'refresh_token' => (string)$setting->refresh_token,
            'shop_id' => (int)$setting->shop_id,
            'partner_id' => (int)$setting->partner_id,
        ];

        $result = $client->postJson($setting->mode, $path, $query, $body);

        if ($result['ok'] && is_array($result['body'])) {
            $access = $result['body']['access_token'] ?? null;
            $refresh = $result['body']['refresh_token'] ?? null;

            if ($access) {
                $raw = ShopeeSetting::query()->first();
                if ($raw) {
                    $raw->access_token = encrypt($access);
                    if ($refresh) {
                        $raw->refresh_token = encrypt($refresh);
                    }
                    $raw->save();
                }
            }
        }

        return redirect()->route('shopee.index')->with('shopee_result', [
            'ok' => (bool)$result['ok'],
            'title' => 'Token Refresh',
            'data' => $result,
        ]);
    }

    public function callApi(Request $request, ShopeeClient $client)
    {
        $data = $request->validate([
            'method' => 'required|in:GET,POST',
            'path' => 'required|string',
            'query_json' => 'nullable|string',
            'body_json' => 'nullable|string',
            'use_access_token' => 'nullable|boolean',
            'use_shop_id' => 'nullable|boolean',
        ]);

        $setting = $this->decryptSetting(ShopeeSetting::query()->first());
        if (!$setting || !$setting->partner_id || !$setting->partner_key) {
            return redirect()->route('shopee.index')->with('shopee_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => 'Partner ID and Partner Key are required.'],
            ]);
        }

        $path = '/' . ltrim($data['path'], '/');
        $timestamp = time();

        $accessToken = (!empty($data['use_access_token']) && $setting->access_token) ? (string)$setting->access_token : null;
        $shopId = (!empty($data['use_shop_id']) && $setting->shop_id) ? (int)$setting->shop_id : null;

        $sign = $client->sign((int)$setting->partner_id, (string)$setting->partner_key, $path, $timestamp, $accessToken, $shopId);

        $query = [
            'partner_id' => (int)$setting->partner_id,
            'timestamp' => $timestamp,
            'sign' => $sign,
        ];

        if ($accessToken !== null) {
            $query['access_token'] = $accessToken;
        }
        if ($shopId !== null) {
            $query['shop_id'] = $shopId;
        }

        $extraQuery = [];
        if (!empty($data['query_json'])) {
            $decoded = json_decode($data['query_json'], true);
            if (is_array($decoded)) {
                $extraQuery = $decoded;
            }
        }
        $query = array_merge($query, $extraQuery);

        $body = [];
        if (!empty($data['body_json'])) {
            $decoded = json_decode($data['body_json'], true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }

        $result = $data['method'] === 'POST'
            ? $client->postJson($setting->mode, $path, $query, $body)
            : $client->get($setting->mode, $path, $query);

        return redirect()->route('shopee.index')->with('shopee_result', [
            'ok' => (bool)$result['ok'],
            'title' => 'API Call Result',
            'data' => $result,
        ]);
    }

    private function decryptSetting(?ShopeeSetting $setting): ?ShopeeSetting
    {
        if (!$setting) {
            return null;
        }

        // Clone in-memory values without mutating DB payload
        $setting = $setting->replicate();
        $setting->id = ShopeeSetting::query()->value('id'); // retain id for UI only

        foreach (['partner_key', 'access_token', 'refresh_token'] as $field) {
            if (!empty($setting->{$field})) {
                try {
                    $setting->{$field} = decrypt($setting->{$field});
                } catch (\Throwable $e) {
                    // If decrypt fails, leave as-is (corrupt or plain text from old installs)
                }
            }
        }

        return $setting;
    }
}
