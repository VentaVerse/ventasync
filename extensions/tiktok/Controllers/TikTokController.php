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
    use \App\Http\Controllers\Concerns\ReturnsToSettingsTab;

    protected function settingsTabRoute(): array
    {
        $store = TikTokSetting::defaultStore();

        return ['ext.tiktok.index', $store ? ['store' => $store->id] : []];
    }

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
        // TikTok returns Unix timestamps, not seconds from now; cap at 2037-12-31 to avoid MySQL TIMESTAMP overflow.
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

    public function index()
    {
        $setting = TikTokSetting::defaultStore();

        $logs = \App\Support\ApiLogPanel::paginate(
            TikTokApiLog::query(), 'api_path', fn ($q) => $q->where('ok', 0)
        );

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

        $tikTokReturnStatuses = [];
        foreach (\Extensions\tiktok\Services\TikTokReturnsPanel::STATUS_MAP as $key => [$label, $tone]) {
            $tikTokReturnStatuses[$key] = $label;
        }
        $returnStatusMap = TikTokOrderStatusMap::where('context', 'return')
            ->pluck('order_status_id', 'tiktok_status')->all();

        return view('ext-tiktok::index', [
            'setting' => $setting?->decrypted(),
            'defaultRedirect' => $this->computedRedirectUri(),
            'result' => session('tiktok_result'),
            'logs' => $logs,
            'orderStatusMap' => $orderStatusMap,
            'erpOrderStatuses' => $erpOrderStatuses,
            'tikTokStatuses' => $tikTokStatuses,
            'tikTokReturnStatuses' => $tikTokReturnStatuses,
            'returnStatusMap' => $returnStatusMap,
        ]);
    }

    // Must be HTTPS and byte-match the URI registered in the TikTok Shop console; derive it, never let a user type it.
    private function computedRedirectUri(): string
    {
        return preg_replace('/^http:/i', 'https:', route('ext.tiktok.callback'));
    }

    public function save(Request $request)
    {
        $data = $request->validate([
            'store_name' => 'nullable|string|max:128',
            'enabled' => 'nullable|boolean',
            'env' => 'nullable|in:live,sandbox',
            'app_key' => 'nullable|string|max:64',
            'app_secret' => 'nullable|string|max:255',
            'region' => 'nullable|string|max:16',
            'sandbox_app_key' => 'nullable|string|max:64',
            'sandbox_app_secret' => 'nullable|string|max:255',
        ]);

        $setting = TikTokSetting::defaultStore() ?? new TikTokSetting();
        if (array_key_exists('store_name', $data) && $data['store_name'] !== null) {
            $setting->store_name = $data['store_name'];
        }
        if (array_key_exists('enabled', $data) && $data['enabled'] !== null) {
            $setting->enabled = (bool) $data['enabled'];
        }

        $env = $data['env'] ?? 'live';
        $setting->mode = $env;

        if ($env === 'live') {
            if (!empty($data['app_key']))    $setting->app_key = $data['app_key'];
            if (!empty($data['app_secret'])) $setting->app_secret = encrypt(trim($data['app_secret']));
            if (!empty($data['region']))     $setting->region = $data['region'];
        } else {
            if (!empty($data['sandbox_app_key']))    $setting->sandbox_app_key = $data['sandbox_app_key'];
            if (!empty($data['sandbox_app_secret'])) $setting->sandbox_app_secret = encrypt(trim($data['sandbox_app_secret']));
        }

        $setting->save();

        $label = $env === 'sandbox' ? 'Sandbox' : 'Production';

        return $this->redirectToSettings()->with('status', "{$label} settings saved.");
    }

    public function toggleMode(Request $request)
    {
        $data = $request->validate(['mode' => 'required|in:live,sandbox']);
        $setting = TikTokSetting::defaultStore() ?? new TikTokSetting();
        $setting->mode = $data['mode'];
        $setting->save();

        $label = $data['mode'] === 'sandbox' ? 'Sandbox' : 'Production';

        return $this->redirectToSettings()->with('status', "Switched to {$label} mode.");
    }

    public function redirectToAuth(Request $request, TikTokClient $client)
    {
        $raw = $request->query('store')
            ? TikTokSetting::query()->find((int) $request->query('store'))
            : TikTokSetting::defaultStore();
        if ($raw !== null) {
            app()->instance('tiktok.route-store', $raw);
        }

        $setting = $raw?->decrypted();
        $sandbox = $raw && $raw->mode === 'sandbox';
        $activeKey = $sandbox ? ($setting->sandbox_app_key ?? '') : ($setting->app_key ?? '');

        if (!$setting || !$activeKey) {
            return $this->redirectToSettings()->with('tiktok_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => 'App Key is required to authorize.'],
            ]);
        }

        $state = self::oauthStateToken((int) $raw->id, $sandbox);
        session(['tiktok_oauth_sandbox' => $sandbox]);

        $url = $client->authUrl($activeKey, $state);

        return redirect()->away($url);
    }

    private function redirectToSettings()
    {
        $store = TikTokSetting::defaultStore();

        return $store !== null
            ? redirect()->route('ext.tiktok.index', ['store' => $store->id])
            : redirect()->route('channels.module', ['module' => 'tiktok']);
    }

    public static function oauthStateToken(int $storeId, bool $isSandbox): string
    {
        $payload = $storeId . '.' . ($isSandbox ? '1' : '0') . '.' . Str::random(24);

        return $payload . '.' . hash_hmac('sha256', $payload, (string) config('app.key'));
    }

    private function parseOAuthState(string $state): ?array
    {
        if (! preg_match('/^(\d+)\.([01])\.([A-Za-z0-9]+)\.([0-9a-f]{64})$/', $state, $m)) {
            return null;
        }

        $payload = $m[1] . '.' . $m[2] . '.' . $m[3];
        if (! hash_equals(hash_hmac('sha256', $payload, (string) config('app.key')), $m[4])) {
            return null;
        }

        return ['store_id' => (int) $m[1], 'sandbox' => $m[2] === '1'];
    }

    private function bindStoreFromState(string $state): array
    {
        $parsed = $this->parseOAuthState($state);
        if ($parsed !== null) {
            $store = TikTokSetting::query()->find($parsed['store_id']);
            if ($store !== null) {
                app()->instance('tiktok.route-store', $store);
                \Illuminate\Support\Facades\URL::defaults(['store' => (int) $store->id]);

                return [(int) $store->id, $parsed['sandbox']];
            }
        }

        return [TikTokSetting::defaultStore()?->id, (bool) session('tiktok_oauth_sandbox', false)];
    }

    public function createStore(Request $request)
    {
        $data = $request->validate([
            'store_name' => ['required', 'string', 'max:128'],
        ]);

        $setting = TikTokSetting::create([
            'store_name' => $data['store_name'],
            'enabled'    => false,
            'mode'       => 'sandbox',
        ]);

        \App\Services\ActivityLogger::log('created', 'TikTok Store', $setting->id, $setting->store_name);

        return redirect()
            ->route('ext.tiktok.index', ['store' => $setting->id])
            ->with('status', 'Store created. Add the app credentials and region, then authorize it to start syncing.');
    }

    public function callback(Request $request, TikTokClient $client)
    {
        $code = (string) $request->query('code', '');
        $state = (string) $request->query('state', '');

        $saved = false;
        $saveError = null;

        // Refuse a code with a forged, tampered or absent state before binding a store or writing a token.
        $stateOk = $this->parseOAuthState($state) !== null;
        if ($code !== '' && ! $stateOk) {
            return $this->redirectToSettings()->with('tiktok_result', [
                'ok' => false,
                'title' => 'OAuth refused',
                'data' => ['message' => 'This authorisation link could not be verified and was not used. Start again from the TikTok settings screen.'],
            ]);
        }

        [$storeId, $sandbox] = $this->bindStoreFromState($state);

        $tokenResult = null;
        $raw = TikTokSetting::defaultStore();
        $setting = $raw?->decrypted();
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

        return $this->redirectToSettings()->with('settings_setup', $saved)->with('tiktok_result', [
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

    public function tokenGet(Request $request, TikTokClient $client)
    {
        $request->validate(['code' => 'required|string']);

        $raw = TikTokSetting::defaultStore();
        $setting = $raw?->decrypted();
        $sandbox = $raw && $raw->mode === 'sandbox';
        $activeKey = $sandbox ? ($setting->sandbox_app_key ?? '') : ($setting->app_key ?? '');
        $activeSecret = $sandbox ? ($setting->sandbox_app_secret ?? '') : ($setting->app_secret ?? '');

        if (!$setting || !$activeKey || !$activeSecret) {
            return $this->toSettingsTab('connection')->with('tiktok_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => 'App Key and App Secret are required.'],
            ]);
        }

        $result = $client->getToken($activeKey, $activeSecret, (string) $request->input('code'));

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

        return $this->toSettingsTab('connection')->with('tiktok_result', [
            'ok' => (bool) $result['ok'],
            'title' => 'Token Get',
            'data' => $result,
        ]);
    }

    public function tokenRefresh(TikTokClient $client)
    {
        $raw = TikTokSetting::defaultStore();
        $setting = $raw?->decrypted();
        $sandbox = $raw && $raw->mode === 'sandbox';
        $activeKey = $sandbox ? ($setting->sandbox_app_key ?? '') : ($setting->app_key ?? '');
        $activeSecret = $sandbox ? ($setting->sandbox_app_secret ?? '') : ($setting->app_secret ?? '');
        $activeRefresh = $sandbox ? ($setting->sandbox_refresh_token ?? '') : ($setting->refresh_token ?? '');

        if (!$setting || !$activeKey || !$activeSecret || !$activeRefresh) {
            return $this->toSettingsTab('connection')->with('tiktok_result', [
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

        return $this->toSettingsTab('connection')->with('tiktok_result', [
            'ok' => (bool) $result['ok'],
            'title' => 'Token Refresh',
            'data' => $result,
        ]);
    }

    public function getShops(TikTokClient $client)
    {
        $raw = TikTokSetting::defaultStore();
        $setting = $raw?->decrypted();
        $sandbox = $raw && $raw->mode === 'sandbox';
        $activeKey = $sandbox ? ($setting->sandbox_app_key ?? '') : ($setting->app_key ?? '');
        $activeSecret = $sandbox ? ($setting->sandbox_app_secret ?? '') : ($setting->app_secret ?? '');
        $activeToken = $sandbox ? ($setting->sandbox_access_token ?? '') : ($setting->access_token ?? '');

        if (!$setting || !$activeKey || !$activeSecret || !$activeToken) {
            return $this->toSettingsTab('connection')->with('tiktok_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => 'App Key, App Secret, and Access Token are required.'],
            ]);
        }

        $path = '/authorization/202309/shops';
        $result = $client->get($activeKey, $activeSecret, $activeToken, $path);

        $body = $result['body'] ?? null;
        $shops = is_array($body) ? ($body['data']['shops'] ?? []) : [];

        if ($result['ok'] && !empty($shops)) {
            $shop = $shops[0];
            $shopIdCol = $sandbox ? 'sandbox_shop_id' : 'shop_id';
            $cipherCol = $sandbox ? 'sandbox_shop_cipher' : 'shop_cipher';
            $nameCol = $sandbox ? 'sandbox_shop_name' : 'shop_name';

            $codeCol = $sandbox ? 'sandbox_shop_code' : 'shop_code';

            $raw->$shopIdCol = $shop['id'] ?? null;
            $raw->$cipherCol = $shop['cipher'] ?? null;
            $raw->$codeCol = $shop['code'] ?? null;
            $raw->$nameCol = $shop['name'] ?? null;
            $raw->region = $shop['region'] ?? $raw->region;

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

        return $this->toSettingsTab('connection')->with('tiktok_result', [
            'ok' => (bool) $result['ok'],
            'title' => 'Get Authorized Shops',
            'data' => $result,
        ]);
    }

    public function setupStep(string $step, \Extensions\tiktok\Services\TikTok\TikTokStoreSetup $setup)
    {
        $raw = TikTokSetting::defaultStore();
        $c = $raw ? \Extensions\tiktok\Services\TikTok\TikTokStoreSetup::creds($raw) : null;
        if (! $raw || ! $c || $c['app_key'] === '' || $c['app_secret'] === '' || $c['token'] === '') {
            return response()->json(['ok' => false, 'count' => 0, 'message' => 'This store is not connected yet. Authorise it with TikTok Shop first.'], 422);
        }
        $out = match ($step) {
            'shop' => $setup->shop($raw),
            'categories' => $setup->categories($raw),
            default => null,
        };
        abort_if($out === null, 404);

        return response()->json($out);
    }

    public function explorerRun(Request $request, TikTokClient $client)
    {
        $data = $request->validate([
            'method' => 'required|in:GET,POST',
            'api_path' => 'required|string|max:255',
            'auth_required' => 'nullable|boolean',
            'params_json' => 'nullable|string|max:20000',
        ]);

        $setting = TikTokSetting::defaultStore()?->decrypted();
        if (!$setting || !$setting->app_key || !$setting->app_secret) {
            return $this->toSettingsTab('explorer')->with('tiktok_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => 'App Key and App Secret are required.'],
            ]);
        }

        $authRequired = (bool) ($data['auth_required'] ?? false);
        if ($authRequired && !$setting->access_token) {
            return $this->toSettingsTab('explorer')->with('tiktok_result', [
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
                return $this->toSettingsTab('explorer')->withInput()->with('tiktok_result', [
                    'ok' => false,
                    'title' => 'Invalid JSON',
                    'data' => ['message' => $e->getMessage()],
                ]);
            }
        }

        $queryParams = [];
        $bodyParams = [];

        if ($data['method'] === 'POST') {
            $bodyParams = $customParams;
        } else {
            $queryParams = $customParams;
        }

        $shopCipher = null;
        if ($authRequired) {
            $raw = TikTokSetting::defaultStore();
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

        return $this->toSettingsTab('explorer')->withInput()->with('tiktok_result', [
            'ok' => (bool) $result['ok'],
            'title' => 'API Explorer: ' . $method . ' ' . $apiPath,
            'data' => $result,
        ]);
    }

    public function packsRun(Request $request, TikTokClient $client)
    {
        $data = $request->validate([
            'pack' => 'required|in:shops,products,orders,logistics,finance,full',
        ]);

        $setting = TikTokSetting::defaultStore()?->decrypted();
        if (!$setting || !$setting->app_key || !$setting->app_secret || !$setting->access_token) {
            return $this->toSettingsTab('explorer')->with('tiktok_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => 'App Key, App Secret, and Access Token are required for preset packs.'],
            ]);
        }

        $raw = TikTokSetting::defaultStore();
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

        return $this->toSettingsTab('explorer')->with('tiktok_result', [
            'ok' => collect($results)->every(fn($r) => $r['ok']),
            'title' => 'Preset Pack: ' . strtoupper($data['pack']),
            'data' => $results,
        ]);
    }

    public function setApiLogMode(Request $request)
    {
        $data = $request->validate(['mode' => 'required|in:' . implode(',', \App\Support\ApiLogMode::MODES)]);
        $setting = TikTokSetting::defaultStore();
        if (!$setting) {
            return $this->toSettingsTab('logs')->with('error', 'TikTok settings not found.');
        }

        $setting->update(['api_log_mode' => $data['mode']]);

        return $this->toSettingsTab('logs')
            ->with('status', 'TikTok API log now records: ' . \App\Support\ApiLogMode::labels()[$data['mode']] . '.');
    }

    public function clearApiLogs()
    {
        $count = TikTokApiLog::count();
        TikTokApiLog::truncate();

        return $this->toSettingsTab('logs')->with('status', "Deleted {$count} TikTok API log entries.");
    }

    public function destroyStore(Request $request, \Extensions\tiktok\Services\TikTok\TikTokStoreDeleter $deleter)
    {
        abort_unless($request->user()?->hasPermission('manage_tiktok/settings'), 403);
        $store = \Extensions\tiktok\Models\TikTokSetting::defaultStore();
        abort_if(! $store, 404);

        $request->validate([
            'confirm_name' => ['required', 'string', function ($attribute, $value, $fail) use ($store) {
                if ((string) $value !== (string) $store->store_name) {
                    $fail('Type the store name exactly as it is shown to confirm.');
                }
            }],
        ]);

        $name = (string) $store->store_name;
        $id = (int) $store->id;
        $removed = $deleter->delete($store);
        \App\Services\ActivityLogger::log('tiktok.store.deleted', 'tiktok_settings', $id, $name, ['removed' => $removed]);

        return redirect()->route('channels.index')
            ->with('status', "Deleted TikTok store {$name}. Sales already imported keep its name.");
    }

    public function purgeRaw(Request $request)
    {
        $storeId = app()->bound('tiktok.route-store') ? (int) app('tiktok.route-store')->id : null;
        $request->validate([
            'days' => ['required', 'integer', 'min:1', 'max:3650'],
        ]);

        $cutoff = now()->subDays((int) $request->days);
        $tables = [
            'tiktok_orders',
            'tiktok_order_products',
        ];

        $total = 0;
        foreach ($tables as $table) {
            $total += DB::table($table)
                ->when($storeId !== null && \Illuminate\Support\Facades\Schema::hasColumn($table, 'tiktok_setting_id'), fn ($q) => $q->where('tiktok_setting_id', $storeId))
                ->whereNotNull('raw')
                ->where('created_at', '<', $cutoff)
                ->update(['raw' => null]);
        }

        \App\Services\ActivityLogger::log('purged', 'TikTokSetting', null, "Raw data ({$request->days} days)");

        return $this->toSettingsTab('logs')->with('status', "Purged raw payloads from {$total} TikTok row" . ($total === 1 ? '' : 's') . " older than {$request->days} days.");
    }

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

        return $this->toSettingsTab('status')->with('status', 'Order status mapping saved.');
    }
}
