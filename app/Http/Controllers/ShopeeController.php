<?php

namespace App\Http\Controllers;

use App\Models\ShopeeApiLog;
use App\Models\ShopeeOrderStatusMap;
use App\Models\ShopeeSetting;
use App\Services\Shopee\ShopeeClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
                    $expiresIn = $result['body']['expire_in'] ?? null;
                    if (is_numeric($expiresIn)) {
                        $raw->expires_at = now()->addSeconds((int)$expiresIn);
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

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $orderStatusMap = ShopeeOrderStatusMap::pluck('order_status_id', 'shopee_status')->all();

        $erpOrderStatuses = DB::table($pfx . 'order_status')
            ->where('language_id', $langId)
            ->orderBy('order_status_id')
            ->get(['order_status_id', 'name']);

        $shopeeStatuses = [
            'UNPAID'          => 'Unpaid',
            'READY_TO_SHIP'   => 'Ready to Ship',
            'PROCESSED'       => 'Processed',
            'SHIPPED'         => 'Shipped',
            'COMPLETED'       => 'Completed',
            'CANCELLED'       => 'Cancelled',
            'IN_CANCEL'       => 'In Cancel',
            'INVOICE_PENDING' => 'Invoice Pending',
        ];

        $logs = ShopeeApiLog::query()
            ->orderByDesc('id')
            ->limit(25)
            ->get();

        return view('shopee.index', [
            'setting' => $this->decryptSetting($setting),
            'defaultRedirect' => 'https://shop-a.example',
            'result' => session('shopee_result'),
            'orderStatusMap' => $orderStatusMap,
            'erpOrderStatuses' => $erpOrderStatuses,
            'shopeeStatuses' => $shopeeStatuses,
            'logs' => $logs,
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
        $data = $request->validate([
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
            (string)$data['code'],
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
                    $expiresIn = $result['body']['expire_in'] ?? null;
                    if (is_numeric($expiresIn)) {
                        $raw->expires_at = now()->addSeconds((int)$expiresIn);
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
        $sign = $client->signAuth((int)$setting->partner_id, (string)$setting->partner_key, $path, $timestamp);

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
                    $expiresIn = $result['body']['expire_in'] ?? null;
                    if (is_numeric($expiresIn)) {
                        $raw->expires_at = now()->addSeconds((int)$expiresIn);
                    }
                    $raw->save();
                }
                Cache::forget('shopee_sync_paused');
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

        if ($accessToken && $shopId) {
            $sign = $client->signShop((int)$setting->partner_id, (string)$setting->partner_key, $path, $timestamp, $accessToken, $shopId);
        } else {
            $sign = $client->signAuth((int)$setting->partner_id, (string)$setting->partner_key, $path, $timestamp);
        }

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

    public function saveOrderStatusMap(Request $request)
    {
        $map = $request->input('map', []);

        if (!is_array($map)) {
            return back()->with('status', 'Invalid mapping data.');
        }

        foreach ($map as $shopeeStatus => $orderStatusId) {
            $shopeeStatus = strtoupper(trim((string) $shopeeStatus));
            $orderStatusId = (int) $orderStatusId;

            if ($shopeeStatus === '' || $orderStatusId <= 0) {
                continue;
            }

            ShopeeOrderStatusMap::updateOrCreate(
                ['shopee_status' => $shopeeStatus],
                ['order_status_id' => $orderStatusId]
            );
        }

        return back()->with('status', 'Order status mapping saved.');
    }

    public function saveSyncDays(Request $request)
    {
        $request->validate([
            'sync_last_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $setting = ShopeeSetting::query()->first();
        if (!$setting) {
            return back()->with('error', 'Shopee settings not found.');
        }

        $setting->update([
            'sync_last_days' => $request->input('sync_last_days') ?: null,
        ]);

        $days = $request->input('sync_last_days');
        $msg = $days
            ? "Order sync window set to last {$days} days."
            : 'Order sync window cleared (defaults to 14 days).';

        return back()->with('status', $msg);
    }

    public function toggleLogging(Request $request)
    {
        $setting = ShopeeSetting::query()->first();
        if (!$setting) {
            return back()->with('error', 'Shopee settings not found.');
        }

        $setting->update([
            'api_logging' => (bool) $request->input('api_logging'),
        ]);

        $state = $setting->api_logging ? 'enabled' : 'disabled';
        return back()->with('status', "Shopee API logging {$state}.");
    }

    public function clearApiLogs()
    {
        $count = ShopeeApiLog::count();
        ShopeeApiLog::truncate();

        return back()->with('status', "Deleted {$count} Shopee API log entries.");
    }

    public function explorerRun(Request $request, ShopeeClient $client)
    {
        $data = $request->validate([
            'method' => 'required|in:GET,POST',
            'api_path' => 'required|string|max:255',
            'use_access_token' => 'nullable|boolean',
            'use_shop_id' => 'nullable|boolean',
            'params_json' => 'nullable|string|max:20000',
        ]);

        $setting = $this->decryptSetting(ShopeeSetting::query()->first());
        if (!$setting || !$setting->partner_id || !$setting->partner_key) {
            return redirect()->route('shopee.index')->with('shopee_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => 'Partner ID and Partner Key are required.'],
            ]);
        }

        $path = (string)$data['api_path'];
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        $useAccessToken = (bool)($data['use_access_token'] ?? false);
        $useShopId = (bool)($data['use_shop_id'] ?? false);

        $customParams = [];
        $jsonRaw = trim((string)($data['params_json'] ?? ''));
        if ($jsonRaw !== '') {
            try {
                $decoded = json_decode($jsonRaw, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $customParams = $decoded;
                }
            } catch (\Throwable $e) {
                return redirect()->route('shopee.index')->withInput()->with('shopee_result', [
                    'ok' => false,
                    'title' => 'Invalid JSON',
                    'data' => ['message' => $e->getMessage()],
                ]);
            }
        }

        $timestamp = time();
        $accessToken = ($useAccessToken && $setting->access_token) ? (string)$setting->access_token : null;
        $shopId = ($useShopId && $setting->shop_id) ? (int)$setting->shop_id : null;

        if ($accessToken && $shopId) {
            $sign = $client->signShop((int)$setting->partner_id, (string)$setting->partner_key, $path, $timestamp, $accessToken, $shopId);
        } else {
            $sign = $client->signAuth((int)$setting->partner_id, (string)$setting->partner_key, $path, $timestamp);
        }

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

        // For GET requests, merge custom params into query
        // For POST requests, they go into body
        $body = [];
        if ($data['method'] === 'GET') {
            $query = array_merge($query, $customParams);
        } else {
            $body = $customParams;
        }

        $result = $data['method'] === 'POST'
            ? $client->postJson($setting->mode, $path, $query, $body)
            : $client->get($setting->mode, $path, $query);

        ShopeeApiLog::safeCreate([
            'pack' => null,
            'method' => $data['method'],
            'api_path' => $path,
            'auth_required' => $useAccessToken,
            'request_params' => $customParams,
            'response_status' => $result['status'] ?? null,
            'ok' => (bool)($result['ok'] ?? false),
            'response_body' => $result['body'] ?? null,
            'user_id' => auth()->id(),
        ]);

        return redirect()->route('shopee.index')->with('shopee_result', [
            'ok' => (bool)$result['ok'],
            'title' => 'API Explorer',
            'data' => $result,
        ]);
    }

    public function packsRun(Request $request, ShopeeClient $client)
    {
        $data = $request->validate([
            'pack' => 'required|in:shop_info,catalog,orders,logistics,full',
        ]);

        $setting = $this->decryptSetting(ShopeeSetting::query()->first());
        if (!$setting || !$setting->partner_id || !$setting->partner_key || !$setting->access_token || !$setting->shop_id) {
            return redirect()->route('shopee.index')->with('shopee_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => 'Partner ID, Partner Key, Access Token, and Shop ID are required for preset packs.'],
            ]);
        }

        $packs = [
            'shop_info' => [
                ['GET', '/api/v2/shop/get_shop_info', []],
                ['GET', '/api/v2/shop/get_profile', []],
            ],
            'catalog' => [
                ['GET', '/api/v2/product/get_category', ['language' => $setting->region ?: 'en']],
                ['GET', '/api/v2/product/get_item_list', ['offset' => 0, 'page_size' => 10, 'item_status' => 'NORMAL']],
            ],
            'orders' => [
                ['GET', '/api/v2/order/get_order_list', [
                    'order_status' => 'READY_TO_SHIP',
                    'time_range_field' => 'create_time',
                    'time_from' => time() - 86400 * 7,
                    'time_to' => time(),
                    'page_size' => 10,
                ]],
            ],
            'logistics' => [
                ['GET', '/api/v2/logistics/get_channel_list', []],
            ],
            'full' => [
                ['GET', '/api/v2/shop/get_shop_info', []],
                ['GET', '/api/v2/product/get_category', ['language' => $setting->region ?: 'en']],
                ['GET', '/api/v2/product/get_item_list', ['offset' => 0, 'page_size' => 10, 'item_status' => 'NORMAL']],
                ['GET', '/api/v2/order/get_order_list', [
                    'order_status' => 'READY_TO_SHIP',
                    'time_range_field' => 'create_time',
                    'time_from' => time() - 86400 * 7,
                    'time_to' => time(),
                    'page_size' => 10,
                ]],
                ['GET', '/api/v2/logistics/get_channel_list', []],
            ],
        ];

        $endpoints = $packs[$data['pack']] ?? [];
        $results = [];

        foreach ($endpoints as [$method, $path, $extra]) {
            $result = $method === 'POST'
                ? $client->shopPost(
                    $setting->mode ?? 'sandbox',
                    (int)$setting->partner_id,
                    (string)$setting->partner_key,
                    (string)$setting->access_token,
                    (int)$setting->shop_id,
                    $path,
                    $extra
                )
                : $client->shopGet(
                    $setting->mode ?? 'sandbox',
                    (int)$setting->partner_id,
                    (string)$setting->partner_key,
                    (string)$setting->access_token,
                    (int)$setting->shop_id,
                    $path,
                    $extra
                );

            ShopeeApiLog::safeCreate([
                'pack' => $data['pack'],
                'method' => $method,
                'api_path' => $path,
                'auth_required' => true,
                'request_params' => $extra,
                'response_status' => $result['status'] ?? null,
                'ok' => (bool)($result['ok'] ?? false),
                'response_body' => $result['body'] ?? null,
                'user_id' => auth()->id(),
            ]);

            $results[] = [
                'method' => $method,
                'path' => $path,
                'ok' => (bool)($result['ok'] ?? false),
                'body' => $result['body'] ?? null,
            ];
        }

        return redirect()->route('shopee.index')->with('shopee_result', [
            'ok' => collect($results)->every(fn($r) => $r['ok']),
            'title' => 'Preset Pack: ' . strtoupper($data['pack']),
            'data' => $results,
        ]);
    }

    public function logisticsChannels(ShopeeClient $client)
    {
        $setting = $this->decryptSetting(ShopeeSetting::query()->first());
        if (!$setting || !$setting->partner_id || !$setting->partner_key || !$setting->access_token || !$setting->shop_id) {
            return redirect()->route('shopee.index')->with('shopee_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => 'Partner ID, Partner Key, Access Token, and Shop ID are required.'],
            ]);
        }

        $path = '/api/v2/logistics/get_channel_list';

        $result = $client->shopGet(
            $setting->mode ?? 'sandbox',
            (int)$setting->partner_id,
            (string)$setting->partner_key,
            (string)$setting->access_token,
            (int)$setting->shop_id,
            $path
        );

        ShopeeApiLog::safeCreate([
            'pack' => 'logistics',
            'method' => 'GET',
            'api_path' => $path,
            'auth_required' => true,
            'request_params' => [],
            'response_status' => $result['status'] ?? null,
            'ok' => (bool)($result['ok'] ?? false),
            'response_body' => $result['body'] ?? null,
            'user_id' => auth()->id(),
        ]);

        return redirect()->route('shopee.index')->with('shopee_result', [
            'ok' => (bool)$result['ok'],
            'title' => 'Logistics Channels',
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
