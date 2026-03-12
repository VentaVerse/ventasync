<?php

namespace Extensions\tiktok\Controllers;

use App\Http\Controllers\Controller;
use Extensions\tiktok\Models\TikTokApiLog;
use Extensions\tiktok\Models\TikTokOrderStatusMap;
use Extensions\tiktok\Models\TikTokSetting;
use Extensions\tiktok\Services\TikTok\TikTokClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TikTokController extends Controller
{
    // -- Token save helper ------------------------------------------------

    private function saveTokenData(TikTokSetting $raw, bool $sandbox, array $tokenData): void
    {
        $access = $tokenData['access_token'] ?? null;
        $refresh = $tokenData['refresh_token'] ?? null;
        $expiresIn = $tokenData['access_token_expire_in'] ?? null;
        $refreshExpiresIn = $tokenData['refresh_token_expire_in'] ?? null;

        $tokenCol = $sandbox ? 'sandbox_access_token' : 'access_token';
        $refreshCol = $sandbox ? 'sandbox_refresh_token' : 'refresh_token';
        $expiresCol = $sandbox ? 'sandbox_expires_at' : 'expires_at';
        $refreshExpiresCol = $sandbox ? 'sandbox_refresh_expires_at' : 'refresh_expires_at';

        if ($access) {
            $raw->$tokenCol = encrypt((string) $access);
        }
        if ($refresh) {
            $raw->$refreshCol = encrypt((string) $refresh);
        }
        // TikTok returns Unix timestamps, not seconds-from-now
        // Cap at 2037-12-31 to avoid MySQL TIMESTAMP overflow (max 2038-01-19)
        $maxTs = \Carbon\Carbon::create(2037, 12, 31, 23, 59, 59);

        if (is_numeric($expiresIn)) {
            $dt = (int) $expiresIn > 1_000_000_000
                ? \Carbon\Carbon::createFromTimestamp((int) $expiresIn)
                : now()->addSeconds((int) $expiresIn);
            $raw->$expiresCol = $dt->greaterThan($maxTs) ? $maxTs : $dt;
        }
        if (is_numeric($refreshExpiresIn)) {
            $dt = (int) $refreshExpiresIn > 1_000_000_000
                ? \Carbon\Carbon::createFromTimestamp((int) $refreshExpiresIn)
                : now()->addSeconds((int) $refreshExpiresIn);
            $raw->$refreshExpiresCol = $dt->greaterThan($maxTs) ? $maxTs : $dt;
        }

        $raw->save();
    }

    // -- Settings page (3-tab view) ---------------------------------------

    public function index()
    {
        $setting = TikTokSetting::query()->first();

        $logs = TikTokApiLog::query()
            ->orderByDesc('id')
            ->limit(25)
            ->get();

        // Order status mapping data
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $orderStatusMap = TikTokOrderStatusMap::where('context', 'order')
            ->pluck('order_status_id', 'tiktok_status')->all();

        $erpOrderStatuses = DB::table($pfx . 'order_status')
            ->where('language_id', $langId)
            ->orderBy('order_status_id')
            ->get(['order_status_id', 'name']);

        $tikTokStatuses = [
            'UNPAID'              => 'Unpaid',
            'ON_HOLD'             => 'On Hold',
            'AWAITING_SHIPMENT'   => 'Awaiting Shipment',
            'AWAITING_COLLECTION' => 'Awaiting Collection',
            'IN_TRANSIT'          => 'In Transit',
            'DELIVERED'           => 'Delivered',
            'COMPLETED'           => 'Completed',
            'CANCELLED'           => 'Cancelled',
        ];

        return view('ext-tiktok::index', [
            'setting' => $setting?->decrypted(),
            'defaultRedirect' => url('/tiktok/callback'),
            'result' => session('tiktok_result'),
            'logs' => $logs,
            'orderStatusMap' => $orderStatusMap,
            'erpOrderStatuses' => $erpOrderStatuses,
            'tikTokStatuses' => $tikTokStatuses,
        ]);
    }

    // -- Save credentials -------------------------------------------------

    public function save(Request $request)
    {
        $data = $request->validate([
            'env' => 'nullable|in:live,sandbox',
            'app_key' => 'nullable|string|max:64',
            'app_secret' => 'nullable|string|max:255',
            'redirect_uri' => 'nullable|string|max:255',
            'region' => 'nullable|string|max:16',
            'sandbox_app_key' => 'nullable|string|max:64',
            'sandbox_app_secret' => 'nullable|string|max:255',
            'sandbox_redirect_uri' => 'nullable|string|max:255',
        ]);

        $setting = TikTokSetting::query()->first() ?? new TikTokSetting();

        $env = $data['env'] ?? 'live';

        if ($env === 'live') {
            $setting->app_key = $data['app_key'] ?? null;
            $setting->redirect_uri = $data['redirect_uri'] ?? null;
            $setting->region = $data['region'] ?? null;

            $setting->app_secret = isset($data['app_secret']) && $data['app_secret'] !== ''
                ? encrypt(trim($data['app_secret']))
                : null;
        } else {
            $setting->sandbox_app_key = $data['sandbox_app_key'] ?? null;
            $setting->sandbox_redirect_uri = $data['sandbox_redirect_uri'] ?? null;

            $setting->sandbox_app_secret = isset($data['sandbox_app_secret']) && $data['sandbox_app_secret'] !== ''
                ? encrypt(trim($data['sandbox_app_secret']))
                : null;
        }

        $setting->save();

        $label = $env === 'sandbox' ? 'Sandbox' : 'Production';
        return redirect()->route('ext.tiktok.index')->with('status', "{$label} settings saved.");
    }

    // -- Toggle mode ------------------------------------------------------

    public function toggleMode(Request $request)
    {
        $data = $request->validate(['mode' => 'required|in:live,sandbox']);
        $setting = TikTokSetting::query()->first() ?? new TikTokSetting();
        $setting->mode = $data['mode'];
        $setting->save();

        $label = $data['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
        return redirect()->route('ext.tiktok.index')->with('status', "Switched to {$label} mode.");
    }

    // -- Redirect to TikTok auth ------------------------------------------

    public function redirectToAuth(TikTokClient $client)
    {
        $raw = TikTokSetting::query()->first();
        $setting = $raw?->decrypted();
        $sandbox = $raw && $raw->mode === 'sandbox';
        $activeKey = $sandbox ? ($setting->sandbox_app_key ?? '') : ($setting->app_key ?? '');

        if (!$setting || !$activeKey) {
            return redirect()->route('ext.tiktok.index')->with('tiktok_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => 'App Key is required to authorize.'],
            ]);
        }

        $state = Str::random(24);
        session(['tiktok_oauth_state' => $state]);

        $url = $client->authUrl($activeKey, $state);

        return redirect()->away($url);
    }

    // -- OAuth callback ---------------------------------------------------

    public function callback(Request $request, TikTokClient $client)
    {
        $code = (string) $request->query('code', '');
        $state = (string) $request->query('state', '');

        $saved = false;
        $saveError = null;

        // Optional state check
        $expected = (string) session('tiktok_oauth_state');
        $stateOk = true;
        if ($expected !== '' && $state !== '' && !hash_equals($expected, $state)) {
            $stateOk = false;
        }

        // Auto token exchange when settings are present
        $tokenResult = null;
        $setting = TikTokSetting::query()->first()?->decrypted();

        // Determine active credentials based on mode
        $raw = TikTokSetting::query()->first();
        $sandbox = $raw && $raw->mode === 'sandbox';
        $activeKey = $sandbox ? ($setting->sandbox_app_key ?? '') : ($setting->app_key ?? '');
        $activeSecret = $sandbox ? ($setting->sandbox_app_secret ?? '') : ($setting->app_secret ?? '');

        if ($code !== '' && $stateOk && $setting && $activeKey && $activeSecret) {
            $tokenResult = $client->getToken($activeKey, $activeSecret, $code);

            $body = $tokenResult['body'] ?? null;
            $tokenData = is_array($body) ? ($body['data'] ?? $body) : null;

            if (($tokenResult['ok'] ?? false) && is_array($tokenData)) {
                $access = $tokenData['access_token'] ?? null;
                $refresh = $tokenData['refresh_token'] ?? null;
                $expiresIn = $tokenData['access_token_expire_in'] ?? null;
                $refreshExpiresIn = $tokenData['refresh_token_expire_in'] ?? null;

                try {
                    $this->saveTokenData($raw ?? new TikTokSetting(), $sandbox, $tokenData);
                    $saved = true;
                } catch (\Throwable $e) {
                    $saveError = $e->getMessage();
                }
            }
        }

        // Log the token exchange
        if ($tokenResult !== null) {
            TikTokApiLog::safeCreate([
                'pack' => 'tiktok.token.get',
                'method' => 'GET',
                'api_path' => '/api/v2/token/get (callback)',
                'auth_required' => false,
                'request_params' => ['grant_type' => 'authorized_code'],
                'response_status' => (int) ($tokenResult['status'] ?? 0),
                'ok' => (bool) ($tokenResult['ok'] ?? false),
                'response_body' => $tokenResult['body'] ?? null,
                'user_id' => auth()->id(),
            ]);
        }

        $message = null;

        if ($code === '') {
            $message = 'No `code` returned to the callback.';
        } elseif (!$stateOk) {
            $message = 'OAuth state mismatch. Re-authorize from the TikTok page.';
        } elseif ($saved) {
            $message = 'Auth code received, token exchanged and saved.';
        } elseif ($saveError) {
            $message = 'Token received but save failed: ' . $saveError;
        } else {
            $message = 'Auth code received but token exchange may have failed.';
        }

        return redirect()->route('ext.tiktok.index')->with('tiktok_result', [
            'ok' => $saved,
            'title' => 'OAuth Callback',
            'data' => [
                'message' => $message,
                'code' => $code !== '' ? substr($code, 0, 8) . '...' : null,
                'state_ok' => $stateOk,
                'token_result' => $tokenResult,
            ],
        ]);
    }

    // -- Token get (manual exchange) --------------------------------------

    public function tokenGet(Request $request, TikTokClient $client)
    {
        $request->validate(['code' => 'required|string']);

        $raw = TikTokSetting::query()->first();
        $setting = $raw?->decrypted();
        $sandbox = $raw && $raw->mode === 'sandbox';
        $activeKey = $sandbox ? ($setting->sandbox_app_key ?? '') : ($setting->app_key ?? '');
        $activeSecret = $sandbox ? ($setting->sandbox_app_secret ?? '') : ($setting->app_secret ?? '');

        if (!$setting || !$activeKey || !$activeSecret) {
            return redirect()->route('ext.tiktok.index')->with('tiktok_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => 'App Key and App Secret are required.'],
            ]);
        }

        $result = $client->getToken($activeKey, $activeSecret, (string) $request->input('code'));

        // Persist tokens if present
        $body = $result['body'] ?? null;
        $tokenData = is_array($body) ? ($body['data'] ?? $body) : null;

        if ($result['ok'] && is_array($tokenData) && $raw) {
            $this->saveTokenData($raw, $sandbox, $tokenData);
        }

        TikTokApiLog::safeCreate([
            'pack' => 'tiktok.token.get',
            'method' => 'GET',
            'api_path' => '/api/v2/token/get',
            'auth_required' => false,
            'request_params' => ['app_key' => (string) $setting->app_key, 'grant_type' => 'authorized_code'],
            'response_status' => (int) ($result['status'] ?? 0),
            'ok' => (bool) ($result['ok'] ?? false),
            'response_body' => $result['body'] ?? null,
            'user_id' => auth()->id(),
        ]);

        return redirect()->route('ext.tiktok.index')->with('tiktok_result', [
            'ok' => (bool) $result['ok'],
            'title' => 'Token Get',
            'data' => $result,
        ]);
    }

    // -- Token refresh ----------------------------------------------------

    public function tokenRefresh(TikTokClient $client)
    {
        $raw = TikTokSetting::query()->first();
        $setting = $raw?->decrypted();
        $sandbox = $raw && $raw->mode === 'sandbox';
        $activeKey = $sandbox ? ($setting->sandbox_app_key ?? '') : ($setting->app_key ?? '');
        $activeSecret = $sandbox ? ($setting->sandbox_app_secret ?? '') : ($setting->app_secret ?? '');
        $activeRefresh = $sandbox ? ($setting->sandbox_refresh_token ?? '') : ($setting->refresh_token ?? '');

        if (!$setting || !$activeKey || !$activeSecret || !$activeRefresh) {
            return redirect()->route('ext.tiktok.index')->with('tiktok_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => 'App Key, App Secret, and Refresh Token are required.'],
            ]);
        }

        $result = $client->refreshToken($activeKey, $activeSecret, $activeRefresh);

        $body = $result['body'] ?? null;
        $tokenData = is_array($body) ? ($body['data'] ?? $body) : null;

        if ($result['ok'] && is_array($tokenData) && $raw) {
            $this->saveTokenData($raw, $sandbox, $tokenData);
        }

        TikTokApiLog::safeCreate([
            'pack' => 'tiktok.token.refresh',
            'method' => 'GET',
            'api_path' => '/api/v2/token/refresh',
            'auth_required' => false,
            'request_params' => ['app_key' => (string) $setting->app_key, 'grant_type' => 'refresh_token'],
            'response_status' => (int) ($result['status'] ?? 0),
            'ok' => (bool) ($result['ok'] ?? false),
            'response_body' => $result['body'] ?? null,
            'user_id' => auth()->id(),
        ]);

        return redirect()->route('ext.tiktok.index')->with('tiktok_result', [
            'ok' => (bool) $result['ok'],
            'title' => 'Token Refresh',
            'data' => $result,
        ]);
    }

    // -- Get authorized shops ---------------------------------------------

    public function getShops(TikTokClient $client)
    {
        $raw = TikTokSetting::query()->first();
        $setting = $raw?->decrypted();
        $sandbox = $raw && $raw->mode === 'sandbox';
        $activeKey = $sandbox ? ($setting->sandbox_app_key ?? '') : ($setting->app_key ?? '');
        $activeSecret = $sandbox ? ($setting->sandbox_app_secret ?? '') : ($setting->app_secret ?? '');
        $activeToken = $sandbox ? ($setting->sandbox_access_token ?? '') : ($setting->access_token ?? '');

        if (!$setting || !$activeKey || !$activeSecret || !$activeToken) {
            return redirect()->route('ext.tiktok.index')->with('tiktok_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => 'App Key, App Secret, and Access Token are required.'],
            ]);
        }

        $path = '/authorization/202309/shops';
        $result = $client->get($activeKey, $activeSecret, $activeToken, $path);

        // Persist shop info if present
        $body = $result['body'] ?? null;
        $shops = is_array($body) ? ($body['data']['shops'] ?? []) : [];

        if ($result['ok'] && !empty($shops)) {
            $shop = $shops[0]; // Use first shop
            $shopIdCol = $sandbox ? 'sandbox_shop_id' : 'shop_id';
            $cipherCol = $sandbox ? 'sandbox_shop_cipher' : 'shop_cipher';
            $nameCol = $sandbox ? 'sandbox_shop_name' : 'shop_name';

            $codeCol = $sandbox ? 'sandbox_shop_code' : 'shop_code';

            $raw->$shopIdCol = $shop['id'] ?? null;
            $raw->$cipherCol = $shop['cipher'] ?? null;
            $raw->$codeCol = $shop['code'] ?? null;
            $raw->$nameCol = $shop['name'] ?? null;
            $raw->region = $shop['region'] ?? $raw->region;

            // Fetch warehouse ID (always needs shop_cipher)
            $whResult = $client->get($activeKey, $activeSecret, $activeToken, '/logistics/202309/warehouses', [], $shop['cipher'] ?? null);
            TikTokApiLog::safeCreate([
                'pack' => 'tiktok.warehouses',
                'method' => 'GET',
                'api_path' => '/logistics/202309/warehouses',
                'auth_required' => true,
                'request_params' => [],
                'response_status' => (int) ($whResult['status'] ?? 0),
                'ok' => (bool) ($whResult['ok'] ?? false),
                'response_body' => $whResult['body'] ?? null,
                'user_id' => auth()->id(),
            ]);
            $warehouses = ($whResult['ok'] ?? false) ? ($whResult['body']['data']['warehouses'] ?? []) : [];
            if (!empty($warehouses)) {
                // Prefer SALES_WAREHOUSE over RETURN_WAREHOUSE
                $salesWh = collect($warehouses)->firstWhere('type', 'SALES_WAREHOUSE');
                $whId = ($salesWh['id'] ?? null) ?: ($warehouses[0]['id'] ?? null);
                $whCol = $sandbox ? 'sandbox_warehouse_id' : 'warehouse_id';
                $raw->$whCol = $whId;
            }

            $raw->save();
        }

        TikTokApiLog::safeCreate([
            'pack' => 'tiktok.shops',
            'method' => 'GET',
            'api_path' => $path,
            'auth_required' => true,
            'request_params' => ['app_key' => (string) $setting->app_key],
            'response_status' => (int) ($result['status'] ?? 0),
            'ok' => (bool) ($result['ok'] ?? false),
            'response_body' => $result['body'] ?? null,
            'user_id' => auth()->id(),
        ]);

        return redirect()->route('ext.tiktok.index')->with('tiktok_result', [
            'ok' => (bool) $result['ok'],
            'title' => 'Get Authorized Shops',
            'data' => $result,
        ]);
    }

    // -- API Explorer -----------------------------------------------------

    public function explorerRun(Request $request, TikTokClient $client)
    {
        $data = $request->validate([
            'method' => 'required|in:GET,POST',
            'api_path' => 'required|string|max:255',
            'auth_required' => 'nullable|boolean',
            'params_json' => 'nullable|string|max:20000',
        ]);

        $setting = TikTokSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->app_key || !$setting->app_secret) {
            return redirect()->route('ext.tiktok.index')->with('tiktok_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => 'App Key and App Secret are required.'],
            ]);
        }

        $authRequired = (bool) ($data['auth_required'] ?? false);
        if ($authRequired && !$setting->access_token) {
            return redirect()->route('ext.tiktok.index')->with('tiktok_result', [
                'ok' => false,
                'title' => 'Missing Access Token',
                'data' => ['message' => 'Access Token is required for auth-required calls. Please authorize first.'],
            ]);
        }

        $apiPath = (string) $data['api_path'];
        if (!str_starts_with($apiPath, '/')) {
            $apiPath = '/' . $apiPath;
        }

        $customParams = [];
        $jsonRaw = trim((string) ($data['params_json'] ?? ''));
        if ($jsonRaw !== '') {
            try {
                $decoded = json_decode($jsonRaw, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $customParams = $decoded;
                }
            } catch (\Throwable $e) {
                return redirect()->route('ext.tiktok.index')->withInput()->with('tiktok_result', [
                    'ok' => false,
                    'title' => 'Invalid JSON',
                    'data' => ['message' => $e->getMessage()],
                ]);
            }
        }

        // Separate body params from query params for POST
        $queryParams = [];
        $bodyParams = [];

        if ($data['method'] === 'POST') {
            // For POST, all custom params go into body by default
            $bodyParams = $customParams;
        } else {
            // For GET, all custom params go into query
            $queryParams = $customParams;
        }

        $shopCipher = null;
        if ($authRequired) {
            $raw = TikTokSetting::query()->first();
            $isSandbox = ($raw->mode ?? 'live') === 'sandbox';
            $shopCipher = $isSandbox ? ($raw->sandbox_shop_cipher ?? null) : ($raw->shop_cipher ?? null);
        }

        $method = (string) $data['method'];

        if ($method === 'POST') {
            $result = $client->post(
                (string) $setting->app_key,
                (string) $setting->app_secret,
                $authRequired ? (string) $setting->access_token : '',
                $apiPath,
                $queryParams,
                $bodyParams,
                $shopCipher
            );
        } else {
            $result = $client->get(
                (string) $setting->app_key,
                (string) $setting->app_secret,
                $authRequired ? (string) $setting->access_token : '',
                $apiPath,
                $queryParams,
                $shopCipher
            );
        }

        TikTokApiLog::safeCreate([
            'pack' => 'tiktok.api.explorer',
            'method' => $method,
            'api_path' => $apiPath,
            'auth_required' => $authRequired,
            'request_params' => $customParams,
            'response_status' => (int) ($result['status'] ?? 0),
            'ok' => (bool) ($result['ok'] ?? false),
            'response_body' => $result['body'] ?? null,
            'user_id' => auth()->id(),
        ]);

        return redirect()->route('ext.tiktok.index')->withInput()->with('tiktok_result', [
            'ok' => (bool) $result['ok'],
            'title' => 'API Explorer: ' . $method . ' ' . $apiPath,
            'data' => $result,
        ]);
    }

    // -- Preset packs -----------------------------------------------------

    public function packsRun(Request $request, TikTokClient $client)
    {
        $data = $request->validate([
            'pack' => 'required|in:shops,products,orders,logistics,finance,full',
        ]);

        $setting = TikTokSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->app_key || !$setting->app_secret || !$setting->access_token) {
            return redirect()->route('ext.tiktok.index')->with('tiktok_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => 'App Key, App Secret, and Access Token are required for preset packs.'],
            ]);
        }

        $raw = TikTokSetting::query()->first();
        $shopCipher = $raw->shop_cipher ?? null;

        $packs = [
            'shops' => [
                ['GET', '/authorization/202309/shops', [], []],
            ],
            'products' => [
                ['POST', '/product/202309/products/search', [], ['page_size' => 10]],
            ],
            'orders' => [
                ['POST', '/order/202309/orders/search', [], [
                    'page_size' => 10,
                    'create_time_ge' => time() - 86400 * 7,
                    'create_time_lt' => time(),
                ]],
            ],
            'logistics' => [
                ['GET', '/logistics/202309/delivery_options', [], []],
            ],
            'finance' => [
                ['POST', '/finance/202309/settlements/search', [], ['page_size' => 10]],
            ],
            'full' => [
                ['GET', '/authorization/202309/shops', [], []],
                ['POST', '/product/202309/products/search', [], ['page_size' => 10]],
                ['POST', '/order/202309/orders/search', [], [
                    'page_size' => 10,
                    'create_time_ge' => time() - 86400 * 7,
                    'create_time_lt' => time(),
                ]],
                ['GET', '/logistics/202309/delivery_options', [], []],
            ],
        ];

        $endpoints = $packs[$data['pack']] ?? [];
        $results = [];

        foreach ($endpoints as [$method, $path, $queryParams, $bodyParams]) {
            if ($method === 'POST') {
                $result = $client->post(
                    (string) $setting->app_key,
                    (string) $setting->app_secret,
                    (string) $setting->access_token,
                    $path,
                    $queryParams,
                    $bodyParams,
                    $shopCipher
                );
            } else {
                $result = $client->get(
                    (string) $setting->app_key,
                    (string) $setting->app_secret,
                    (string) $setting->access_token,
                    $path,
                    $queryParams,
                    $shopCipher
                );
            }

            TikTokApiLog::safeCreate([
                'pack' => $data['pack'],
                'method' => $method,
                'api_path' => $path,
                'auth_required' => true,
                'request_params' => $method === 'POST' ? $bodyParams : $queryParams,
                'response_status' => $result['status'] ?? null,
                'ok' => (bool) ($result['ok'] ?? false),
                'response_body' => $result['body'] ?? null,
                'user_id' => auth()->id(),
            ]);

            $results[] = [
                'method' => $method,
                'path' => $path,
                'ok' => (bool) ($result['ok'] ?? false),
                'body' => $result['body'] ?? null,
            ];
        }

        return redirect()->route('ext.tiktok.index')->with('tiktok_result', [
            'ok' => collect($results)->every(fn($r) => $r['ok']),
            'title' => 'Preset Pack: ' . strtoupper($data['pack']),
            'data' => $results,
        ]);
    }

    // -- Toggle logging ---------------------------------------------------

    public function toggleLogging(Request $request)
    {
        $setting = TikTokSetting::query()->first();
        if (!$setting) {
            return back()->with('error', 'TikTok settings not found.');
        }

        $setting->update([
            'api_logging' => (bool) $request->input('api_logging'),
        ]);

        $state = $setting->api_logging ? 'enabled' : 'disabled';
        return back()->with('status', "TikTok API logging {$state}.");
    }

    // -- Clear API logs ---------------------------------------------------

    public function clearApiLogs()
    {
        $count = TikTokApiLog::count();
        TikTokApiLog::truncate();

        return back()->with('status', "Deleted {$count} TikTok API log entries.");
    }

    // -- Save sync days ---------------------------------------------------

    public function saveSyncDays(Request $request)
    {
        $request->validate([
            'sync_last_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $setting = TikTokSetting::query()->first();
        if (!$setting) {
            return back()->with('error', 'TikTok settings not found.');
        }

        $setting->update([
            'sync_last_days' => $request->input('sync_last_days') ?: null,
        ]);

        $days = $request->input('sync_last_days');
        $msg = $days
            ? "Order sync window set to last {$days} days."
            : 'Order sync window cleared (defaults to 15 days).';

        return back()->with('status', $msg);
    }

    // -- Save order tab mapping -------------------------------------------

    public function saveOrderStatusMap(Request $request)
    {
        $map = $request->input('map', []);

        foreach ($map as $tikTokStatus => $orderStatusId) {
            $tikTokStatus = strtoupper(trim($tikTokStatus));
            $orderStatusId = (int) $orderStatusId;

            if ($tikTokStatus === '' || $orderStatusId <= 0) {
                continue;
            }

            TikTokOrderStatusMap::updateOrCreate(
                ['tiktok_status' => $tikTokStatus, 'context' => 'order'],
                ['order_status_id' => $orderStatusId]
            );
        }

        return back()->with('status', 'Order status mapping saved.');
    }
}
