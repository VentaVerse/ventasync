<?php

namespace Extensions\Shopee\Controllers;

use App\Http\Controllers\Controller;

use Extensions\Shopee\Models\ShopeeApiLog;
use Extensions\Shopee\Models\ShopeeOrderStatusMap;
use Extensions\Shopee\Models\ShopeeStore;
use Extensions\Shopee\Services\Shopee\ShopeeClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ShopeeController extends Controller
{
    use \App\Http\Controllers\Concerns\ReturnsToSettingsTab;

    protected function settingsTabRoute(): array
    {
        $store = ShopeeStore::defaultStore();

        return $store ? ['ext.shopee.settings.show', ['store' => $store->id]] : ['ext.shopee.index', []];
    }

public function root(Request $request, ShopeeClient $client)
{
    $code = $request->query('code');
    $shopId = $request->query('shop_id');

    if (!$code && !$shopId) {
        return redirect()->route('dashboard');
    }

    $store = ShopeeStore::defaultStore()?->decrypted();
    $mode = $store->mode ?? 'sandbox';

    if ($shopId) {
        $raw = ShopeeStore::defaultStore() ?? new ShopeeStore();
        $this->persistShopId($raw, $mode, (int)$shopId);
        $store = ShopeeStore::defaultStore()?->decrypted();
    }

    $auth = $this->activeAuth($store);

    if ($code && $store && $auth['partner_id'] && $auth['partner_key'] && $auth['shop_id']) {
        $result = $client->exchangeToken(
            $auth['mode'],
            (int)$auth['partner_id'],
            (string)$auth['partner_key'],
            (string)$code,
            (int)$auth['shop_id']
        );

        if ($result['ok'] && is_array($result['body'])) {
            $access = $result['body']['access_token'] ?? null;
            $refresh = $result['body']['refresh_token'] ?? null;

            if ($access) {
                $raw = ShopeeStore::defaultStore();
                if ($raw) {
                    $this->persistTokens($raw, $auth['mode'], (string)$access, $refresh, $result['body']['expire_in'] ?? null);
                }
            }
        }

        return $this->redirectToSettings()->with('shopee_result', [
            'ok' => (bool)$result['ok'],
            'title' => 'OAuth Callback (Domain Redirect)',
            'data' => $result,
        ]);
    }

    return view('ext-shopee::callback', [
        'code' => $code,
        'shop_id' => $shopId,
    ]);
}

public function index(Request $request = null, ShopeeClient $client = null)
    {
        $request = $request ?? request();
        $client = $client ?? app(ShopeeClient::class);

        $cbCode = (string) $request->query('code', '');
        $cbShopId = (string) $request->query('shop_id', '');
        if ($cbCode !== '' || $cbShopId !== '') {
            $rawSetting = ($id = cache()->pull('shopee_oauth_store')) !== null
                ? ShopeeStore::query()->find($id)
                : null;
            if ($rawSetting === null && $cbShopId !== '') {
                $rawSetting = ShopeeStore::query()
                    ->where('shop_id', (int) $cbShopId)
                    ->orWhere('sandbox_shop_id', (int) $cbShopId)
                    ->orderBy('id')->first();
            }
            $rawSetting ??= ShopeeStore::defaultStore();
            if ($rawSetting !== null) {
                app()->instance('shopee.route-store', $rawSetting);
            }
            $rawSetting ??= new ShopeeStore();
            $mode = $rawSetting->mode ?? 'sandbox';
            if ($cbShopId !== '') {
                $this->persistShopId($rawSetting, $mode, (int) $cbShopId);
            }
            $dec = ShopeeStore::defaultStore()?->decrypted();
            $auth = $this->activeAuth($dec);
            if ($cbCode !== '' && $auth['partner_id'] && $auth['partner_key'] && $auth['shop_id']) {
                $result = $client->exchangeToken(
                    $auth['mode'],
                    (int) $auth['partner_id'],
                    (string) $auth['partner_key'],
                    $cbCode,
                    (int) $auth['shop_id']
                );
                $this->logAuthCall('/api/v2/auth/token/get', $result);
                $connected = false;
                if (($result['ok'] ?? false) && is_array($result['body'] ?? null)) {
                    $access = $result['body']['access_token'] ?? null;
                    if ($access) {
                        $raw = ShopeeStore::defaultStore();
                        if ($raw) {
                            $this->persistTokens(
                                $raw,
                                $auth['mode'],
                                (string) $access,
                                $result['body']['refresh_token'] ?? null,
                                $result['body']['expire_in'] ?? null
                            );
                            $connected = true;
                        }
                    }
                }
                $landing = ShopeeStore::defaultStore();

                return ($landing !== null
                        ? redirect()->route('ext.shopee.settings.show', ['store' => $landing->id])
                        : redirect()->route('ext.shopee.index'))
                    ->with('shopee_result', [
                        'ok'    => (bool) ($result['ok'] ?? false),
                        'title' => 'Authorisation',
                        'data'  => $result,
                    ])
                    ->with('settings_setup', $connected);
            }
        }

        $first = ShopeeStore::query()->orderBy('id')->first();
        if ($first !== null) {
            return redirect()->route('ext.shopee.settings.show', ['store' => $first->id]);
        }

        return redirect()->route('channels.module', ['module' => 'shopee']);
    }

    public function showSettings(Request $request)
    {
        $store = ShopeeStore::defaultStore();

        $langId = (int) config('catalog.default_language_id');

        $orderStatusMap = ShopeeOrderStatusMap::where('context', 'order')->pluck('order_status_id', 'shopee_status')->all();

        $salesOrderStatuses = DB::table('order_statuses')
            ->where('language_id', $langId)
            ->orderBy('order_status_id')
            ->get(['order_status_id', 'name']);

        $shopeeStatuses = collect([
            'UNPAID',
            'READY_TO_SHIP',
            'PROCESSED',
            'RETRY_SHIP',
            'SHIPPED',
            'TO_CONFIRM_RECEIVE',
            'COMPLETED',
            'IN_CANCEL',
            'CANCELLED',
            'TO_RETURN',
        ])->merge(
            DB::table('ch_shopee_orders')->whereNotNull('status')->where('status', '!=', '')->distinct()->pluck('status')
                ->map(fn ($s) => strtoupper(trim((string) $s)))
        )->unique()->values()->all();

        $shopeeReturnStatuses = [
            'REQUESTED'           => 'Requested',
            'ACCEPTED'            => 'Accepted',
            'CANCELLED'           => 'Canceled',
            'JUDGING'             => 'Judging',
            'PROCESSING'          => 'Processing',
            'SELLER_DISPUTE'      => 'Seller Dispute',
            'REFUND_PAID'         => 'Refund Paid',
            'CLOSED'              => 'Closed',
            'SELLER_COMPENSATION' => 'Seller Compensation',
        ];

        $returnStatusMap = ShopeeOrderStatusMap::where('context', 'return')
            ->whereIn('shopee_status', array_keys($shopeeReturnStatuses))
            ->pluck('order_status_id', 'shopee_status')
            ->all();

        $logs = \App\Support\ApiLogPanel::paginate(
            ShopeeApiLog::query(), 'api_path', fn ($q) => $q->where('ok', 0)
        );

        return view('ext-shopee::index', [
            'setting' => $store?->decrypted(),
            'defaultRedirect' => $this->computedRedirectUri(),
            'webhookUrl' => preg_replace('/^http:/i', 'https:', route('channels.webhooks.receive', ['channel' => 'shopee'])),
            'result' => session('shopee_result'),
            'orderStatusMap' => $orderStatusMap,
            'salesOrderStatuses' => $salesOrderStatuses,
            'shopeeStatuses' => $shopeeStatuses,
            'shopeeReturnStatuses' => $shopeeReturnStatuses,
            'returnStatusMap' => $returnStatusMap,
            'logs' => $logs,
        ]);
    }

    public function save(Request $request)
    {
        $data = $request->validate([
            'env' => 'nullable|in:live,sandbox',
            'partner_id' => 'nullable|integer|min:1',
            'partner_key' => 'nullable|string',
            'push_partner_key' => 'nullable|string|max:255',
            'apply_order_pushes' => 'nullable|boolean',
            'shop_id' => 'nullable|integer|min:1',
            'access_token' => 'nullable|string',
            'region' => 'nullable|in:sg,my,th,vn,id,ph,tw,br,mx,co,cl,pl',
            'sandbox_partner_id' => 'nullable|integer|min:1',
            'sandbox_partner_key' => 'nullable|string',
            'sandbox_push_partner_key' => 'nullable|string|max:255',
            'sandbox_shop_id' => 'nullable|integer|min:1',
            'sandbox_access_token' => 'nullable|string',
            'sandbox_region' => 'nullable|in:sg,my,th,vn,id,ph,tw,br,mx,co,cl,pl',
            'store_name' => 'nullable|string|max:128',
            'enabled' => 'nullable|boolean',
        ]);

        $store = ShopeeStore::defaultStore() ?? new ShopeeStore();

        if (array_key_exists('store_name', $data) && $data['store_name'] !== null) {
            $store->store_name = $data['store_name'];
        }
        if (array_key_exists('enabled', $data) && $data['enabled'] !== null) {
            $store->enabled = (bool) $data['enabled'];
        }

        if (array_key_exists('apply_order_pushes', $data) && $data['apply_order_pushes'] !== null) {
            $store->apply_order_pushes = (bool) $data['apply_order_pushes'];
        }

        $env = $data['env'] ?? 'live';
        $store->mode = $env;

        if ($env === 'live') {
            if (!empty($data['partner_id']))    $store->partner_id = $data['partner_id'];
            if (!empty($data['shop_id']))       $store->shop_id = $data['shop_id'];
            if (!empty($data['partner_key']))   $store->partner_key = encrypt(trim($data['partner_key']));
            if (!empty($data['push_partner_key'])) $store->push_partner_key = encrypt(trim($data['push_partner_key']));
            if (!empty($data['access_token']))  $store->access_token = encrypt(trim($data['access_token']));
            $store->region = $data['region'] ?? null;
        } else {
            if (!empty($data['sandbox_partner_id']))    $store->sandbox_partner_id = $data['sandbox_partner_id'];
            if (!empty($data['sandbox_shop_id']))       $store->sandbox_shop_id = $data['sandbox_shop_id'];
            if (!empty($data['sandbox_partner_key']))   $store->sandbox_partner_key = encrypt(trim($data['sandbox_partner_key']));
            if (!empty($data['sandbox_push_partner_key'])) $store->sandbox_push_partner_key = encrypt(trim($data['sandbox_push_partner_key']));
            if (!empty($data['sandbox_access_token']))  $store->sandbox_access_token = encrypt(trim($data['sandbox_access_token']));
            $store->sandbox_region = $data['sandbox_region'] ?? null;
        }

        $store->save();

        $label = $env === 'sandbox' ? 'Sandbox' : 'Production';
        return $this->redirectToSettings()->with('status', "{$label} settings saved.");
    }

    public function toggleMode(Request $request)
    {
        $data = $request->validate(['mode' => 'required|in:live,sandbox']);
        $store = ShopeeStore::defaultStore() ?? new ShopeeStore();
        $store->mode = $data['mode'];
        $store->save();

        $label = $data['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
        return $this->redirectToSettings()->with('status', "Switched to {$label} mode.");
    }

    public function callback(Request $request)
    {
        $code = $request->query('code');
        $shopId = $request->query('shop_id');

        if ($shopId) {
            $raw = ShopeeStore::defaultStore() ?? new ShopeeStore();
            $this->persistShopId($raw, $raw->mode ?? 'sandbox', (int)$shopId);
        }

        return view('ext-shopee::callback', [
            'code' => $code,
            'shop_id' => $shopId,
        ]);
    }

    private function activeCredentials(?object $store): array
    {
        $redirect = $this->computedRedirectUri();
        if (!$store) {
            return ['partner_id' => null, 'partner_key' => null, 'redirect_uri' => $redirect, 'mode' => 'sandbox'];
        }
        $mode = $store->mode ?? 'sandbox';
        if ($mode === 'sandbox') {
            return [
                'partner_id'   => $store->sandbox_partner_id ?? null,
                'partner_key'  => $store->sandbox_partner_key ?? null,
                'redirect_uri' => $redirect,
                'mode'         => 'sandbox',
            ];
        }
        return [
            'partner_id'   => $store->partner_id ?? null,
            'partner_key'  => $store->partner_key ?? null,
            'redirect_uri' => $redirect,
            'mode'         => 'live',
        ];
    }

    private function activeAuth(?object $store): array
    {
        if (!$store) {
            return [
                'mode'          => 'sandbox',
                'partner_id'    => null,
                'partner_key'   => null,
                'shop_id'       => null,
                'access_token'  => null,
                'refresh_token' => null,
            ];
        }
        $mode = $store->mode ?? 'sandbox';
        $isSandbox = $mode === 'sandbox';
        return [
            'mode'          => $mode,
            'partner_id'    => $isSandbox ? ($store->sandbox_partner_id    ?? null) : ($store->partner_id    ?? null),
            'partner_key'   => $isSandbox ? ($store->sandbox_partner_key   ?? null) : ($store->partner_key   ?? null),
            'shop_id'       => $isSandbox ? ($store->sandbox_shop_id       ?? null) : ($store->shop_id       ?? null),
            'access_token'  => $isSandbox ? ($store->sandbox_access_token  ?? null) : ($store->access_token  ?? null),
            'refresh_token' => $isSandbox ? ($store->sandbox_refresh_token ?? null) : ($store->refresh_token ?? null),
        ];
    }

    private function persistTokens(ShopeeStore $raw, string $mode, string $access, ?string $refresh, $expireIn): void
    {
        $isSandbox = $mode === 'sandbox';
        if ($isSandbox) {
            $raw->sandbox_access_token = encrypt($access);
            if ($refresh) {
                $raw->sandbox_refresh_token = encrypt($refresh);
            }
            if (is_numeric($expireIn)) {
                $raw->sandbox_expires_at = now()->addSeconds((int)$expireIn);
            }
            if ($refresh) {
                $raw->sandbox_refresh_expires_at = now()->addDays(ShopeeStore::REFRESH_TOKEN_DAYS);
            }
        } else {
            $raw->access_token = encrypt($access);
            if ($refresh) {
                $raw->refresh_token = encrypt($refresh);
                $raw->refresh_expires_at = now()->addDays(ShopeeStore::REFRESH_TOKEN_DAYS);
            }
            if (is_numeric($expireIn)) {
                $raw->expires_at = now()->addSeconds((int)$expireIn);
            }
        }
        $raw->save();
    }

    private function persistShopId(ShopeeStore $raw, string $mode, int $shopId): void
    {
        if ($mode === 'sandbox') {
            $raw->sandbox_shop_id = $shopId;
        } else {
            $raw->shop_id = $shopId;
        }
        $raw->save();
    }

    public function createStore(Request $request)
    {
        $data = $request->validate([
            'store_name' => ['required', 'string', 'max:128'],
        ]);

        $store = ShopeeStore::create([
            'store_name' => $data['store_name'],
            'enabled' => false,
            'mode' => 'sandbox',
        ]);

        \App\Services\ActivityLogger::log('created', 'Shopee Store', $store->id, $store->store_name);

        return redirect()
            ->route('ext.shopee.settings.show', ['store' => $store->id])
            ->with('status', 'Store created. Add the partner credentials and authorize it to start syncing.');
    }

    private function redirectToSettings()
    {
        $store = ShopeeStore::defaultStore();

        return $store !== null
            ? redirect()->route('ext.shopee.settings.show', ['store' => $store->id])
            : redirect()->route('ext.shopee.index');
    }

    private function computedRedirectUri(): string
    {
        return preg_replace('/^http:/i', 'https:', route('ext.shopee.index'));
    }

    public function redirectToShopeeAuth(ShopeeClient $client)
{
    $store = request()->query('store')
        ? ShopeeStore::query()->find((int) request()->query('store'))
        : ShopeeStore::defaultStore();
    if ($store !== null) {
        app()->instance('shopee.route-store', $store);
        cache(['shopee_oauth_store' => $store->id], now()->addMinutes(30));
    }
    $setting = $store?->decrypted();
    $creds = $this->activeCredentials($setting);

    if (!$setting || !$creds['partner_id'] || !$creds['partner_key']) {
        $modeLabel = $creds['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
        return $this->redirectToSettings()->with('shopee_result', [
            'ok' => false,
            'title' => 'Missing settings',
            'data' => ['message' => "Partner ID and Partner Key for {$modeLabel} mode are required to authorize."],
        ]);
    }

    if (empty($creds['redirect_uri'])) {
        $modeLabel = $creds['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
        return $this->redirectToSettings()->with('shopee_result', [
            'ok' => false,
            'title' => 'Missing redirect URI',
            'data' => ['message' => "A {$modeLabel} Redirect URI is required. Configure it in Shopee settings before authorizing."],
        ]);
    }

    $url = $client->buildAuthUrl($creds['mode'], (int)$creds['partner_id'], (string)$creds['partner_key'], $creds['redirect_uri']);

    return redirect()->away($url);
}

public function buildAuthUrl(ShopeeClient $client)
    {
        $store = ShopeeStore::defaultStore()?->decrypted();
        $creds = $this->activeCredentials($store);

        if (!$store || !$creds['partner_id'] || !$creds['partner_key']) {
            $modeLabel = $creds['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return $this->redirectToSettings()->with('shopee_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => "Partner ID and Partner Key for {$modeLabel} mode are required to build the auth URL."],
            ]);
        }

        if (empty($creds['redirect_uri'])) {
            $modeLabel = $creds['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return $this->redirectToSettings()->with('shopee_result', [
                'ok' => false,
                'title' => 'Missing redirect URI',
                'data' => ['message' => "A {$modeLabel} Redirect URI is required. Configure it in Shopee settings before building the auth URL."],
            ]);
        }

        $url = $client->buildAuthUrl($creds['mode'], (int)$creds['partner_id'], (string)$creds['partner_key'], $creds['redirect_uri']);

        return $this->redirectToSettings()->with('shopee_result', [
            'ok' => true,
            'title' => 'Auth URL generated',
            'data' => ['auth_url' => $url],
        ]);
    }

    public function setupStep(string $step, \Extensions\Shopee\Services\Shopee\ShopeeStoreSetup $setup)
    {
        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = ShopeeStore::activeAuth($store);
        if (!$store || !$auth['complete']) {
            return response()->json(['ok' => false, 'count' => 0, 'message' => 'This store is not connected yet. Authorise it with Shopee first.'], 422);
        }

        $out = match ($step) {
            'categories' => $setup->categories($auth),
            'couriers' => $setup->couriers($auth),
            default => null,
        };
        abort_if($out === null, 404);

        return response()->json($out);
    }

    public function destroyStore(Request $request, \Extensions\Shopee\Services\Shopee\ShopeeStoreDeleter $deleter)
    {
        abort_unless($request->user()?->hasPermission('manage_shopee/settings'), 403);
        $store = ShopeeStore::defaultStore();
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
        \App\Services\ActivityLogger::log('shopee.store.deleted', 'Shopee Store', $id, $name, ['removed' => $removed]);

        return redirect()->route('channels.index')
            ->with('status', "Deleted Shopee store {$name}. Sales already imported keep its name.");
    }

    private function logAuthCall(string $path, array $result): void
    {
        ShopeeApiLog::safeCreate([
            'pack' => 'auth',
            'method' => 'POST',
            'api_path' => $path,
            'auth_required' => false,
            'request_params' => [],
            'response_status' => $result['status'] ?? null,
            'ok' => (bool) ($result['ok'] ?? false),
            'response_body' => $result['body'] ?? null,
            'user_id' => auth()->id(),
        ]);
    }

    public function tokenGet(Request $request, ShopeeClient $client)
    {
        $data = $request->validate([
            'code' => 'required|string',
        ]);

        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = $this->activeAuth($store);
        if (!$store || !$auth['partner_id'] || !$auth['partner_key'] || !$auth['shop_id']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return $this->redirectToSettings()->with('shopee_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => "Partner ID, Partner Key, and Shop ID for {$modeLabel} mode are required."],
            ]);
        }

        $result = $client->exchangeToken(
            $auth['mode'],
            (int)$auth['partner_id'],
            (string)$auth['partner_key'],
            (string)$data['code'],
            (int)$auth['shop_id']
        );
        $this->logAuthCall('/api/v2/auth/token/get', $result);

        $connected = false;
        if ($result['ok'] && is_array($result['body'])) {
            $access = $result['body']['access_token'] ?? null;
            $refresh = $result['body']['refresh_token'] ?? null;

            if ($access) {
                $raw = ShopeeStore::defaultStore();
                if ($raw) {
                    $this->persistTokens($raw, $auth['mode'], (string)$access, $refresh, $result['body']['expire_in'] ?? null);
                    $connected = true;
                }
            }
        }

        return $this->redirectToSettings()
            ->with('shopee_result', ['ok' => (bool)$result['ok'], 'title' => 'Token exchange', 'data' => $result])
            ->with('settings_setup', $connected);
    }

    public function tokenRefresh(ShopeeClient $client)
    {
        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = $this->activeAuth($store);
        if (!$store || !$auth['partner_id'] || !$auth['partner_key'] || !$auth['shop_id'] || !$auth['refresh_token']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return $this->redirectToSettings()->with('shopee_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => "Partner ID, Partner Key, Shop ID, and Refresh Token for {$modeLabel} mode are required."],
            ]);
        }

        $path = '/api/v2/auth/access_token/get';
        $timestamp = time();
        $sign = $client->signAuth((int)$auth['partner_id'], (string)$auth['partner_key'], $path, $timestamp);

        $query = [
            'partner_id' => (int)$auth['partner_id'],
            'timestamp' => $timestamp,
            'sign' => $sign,
        ];

        $body = [
            'refresh_token' => (string)$auth['refresh_token'],
            'shop_id' => (int)$auth['shop_id'],
            'partner_id' => (int)$auth['partner_id'],
        ];

        $result = $client->postJson($auth['mode'], $path, $query, $body);
        $this->logAuthCall($path, $result);

        if ($result['ok'] && is_array($result['body'])) {
            $access = $result['body']['access_token'] ?? null;
            $refresh = $result['body']['refresh_token'] ?? null;

            if ($access) {
                $raw = ShopeeStore::defaultStore();
                if ($raw) {
                    $this->persistTokens($raw, $auth['mode'], (string)$access, $refresh, $result['body']['expire_in'] ?? null);
                }
                if ($raw) {
                    Cache::forget('shopee_sync_paused:' . $raw->id);
                }
                Cache::forget('shopee_sync_paused');
            }
        }

        return $this->redirectToSettings()->with('shopee_result', [
            'ok' => (bool)$result['ok'],
            'title' => 'Token refresh',
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

        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = ShopeeStore::activeAuth($store);
        if (!$store || !$auth['partner_id'] || !$auth['partner_key']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return $this->toSettingsTab('explorer')->with('shopee_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => "Partner ID and Partner Key for {$modeLabel} mode are required."],
            ]);
        }

        $path = '/' . ltrim($data['path'], '/');
        $timestamp = time();

        $accessToken = (!empty($data['use_access_token']) && $auth['access_token']) ? (string)$auth['access_token'] : null;
        $shopId = (!empty($data['use_shop_id']) && $auth['shop_id']) ? (int)$auth['shop_id'] : null;

        if ($accessToken && $shopId) {
            $sign = $client->signShop((int)$auth['partner_id'], (string)$auth['partner_key'], $path, $timestamp, $accessToken, $shopId);
        } else {
            $sign = $client->signAuth((int)$auth['partner_id'], (string)$auth['partner_key'], $path, $timestamp);
        }

        $query = [
            'partner_id' => (int)$auth['partner_id'],
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
            ? $client->postJson($store->mode, $path, $query, $body)
            : $client->get($store->mode, $path, $query);

        return $this->toSettingsTab('explorer')->with('shopee_result', [
            'ok' => (bool)$result['ok'],
            'title' => 'API Call Result',
            'data' => $result,
        ]);
    }

    public function saveOrderStatusMap(Request $request)
    {
        $map = $request->input('map', []);

        if (!is_array($map)) {
            return $this->toSettingsTab('status')->with('status', 'Invalid mapping data.');
        }

        foreach ($map as $shopeeStatus => $orderStatusId) {
            $shopeeStatus = strtoupper(trim((string) $shopeeStatus));
            $orderStatusId = (int) $orderStatusId;

            if ($shopeeStatus === '' || $orderStatusId <= 0) {
                continue;
            }

            ShopeeOrderStatusMap::updateOrCreate(
                ['shopee_status' => $shopeeStatus, 'context' => 'order'],
                ['order_status_id' => $orderStatusId]
            );
        }

        return $this->toSettingsTab('status')->with('status', 'Order status mapping saved.');
    }

    public function saveReturnStatusMap(Request $request)
    {
        $map = $request->input('map', []);

        if (!is_array($map)) {
            return $this->toSettingsTab('status')->with('status', 'Invalid mapping data.');
        }

        foreach ($map as $shopeeStatus => $orderStatusId) {
            $shopeeStatus = strtoupper(trim((string) $shopeeStatus));
            $orderStatusId = (int) $orderStatusId;

            if ($shopeeStatus === '') {
                continue;
            }

            if ($orderStatusId <= 0) {
                ShopeeOrderStatusMap::where('shopee_status', $shopeeStatus)
                    ->where('context', 'return')
                    ->delete();
                continue;
            }

            ShopeeOrderStatusMap::updateOrCreate(
                ['shopee_status' => $shopeeStatus, 'context' => 'return'],
                ['order_status_id' => $orderStatusId]
            );
        }

        return $this->toSettingsTab('status')->with('status', 'Return status mapping saved.');
    }

    public function setApiLogMode(Request $request)
    {
        $data = $request->validate(['mode' => 'required|in:' . implode(',', \App\Support\ApiLogMode::MODES)]);
        $store = ShopeeStore::defaultStore();
        if (!$store) {
            return $this->toSettingsTab('logs')->with('error', 'Shopee settings not found.');
        }

        $store->update(['api_log_mode' => $data['mode']]);

        return $this->toSettingsTab('logs')
            ->with('status', 'Shopee API log now records: ' . \App\Support\ApiLogMode::labels()[$data['mode']] . '.');
    }

    public function clearApiLogs()
    {
        $count = ShopeeApiLog::query()->count();
        // Delete this store's rows only; a truncate would wipe every store's log.
        ShopeeApiLog::query()->delete();

        return $this->toSettingsTab('logs')->with('status', "Deleted {$count} Shopee API log entries.");
    }

    public function purgeRaw(Request $request)
    {
        $storeId = app()->bound('shopee.route-store') ? (int) app('shopee.route-store')->id : null;
        $request->validate([
            'days' => ['required', 'integer', 'min:1', 'max:3650'],
        ]);

        $cutoff = now()->subDays((int) $request->days);
        $tables = [
            'ch_shopee_orders',
            'ch_shopee_order_products',
            'ch_shopee_returns',
        ];

        $total = 0;
        foreach ($tables as $table) {
            $total += DB::table($table)
                ->when($storeId !== null && \Illuminate\Support\Facades\Schema::hasColumn($table, 'store_id'), fn ($q) => $q->where('store_id', $storeId))
                ->whereNotNull('raw')
                ->where('created_at', '<', $cutoff)
                ->update(['raw' => null]);
        }

        \App\Services\ActivityLogger::log('purged', 'ShopeeStore', null, "Raw data ({$request->days} days)");

        return $this->toSettingsTab('logs')
            ->with('status', "Purged raw payloads from {$total} Shopee row" . ($total === 1 ? '' : 's') . " older than {$request->days} days.");
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

        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = ShopeeStore::activeAuth($store);
        if (!$store || !$auth['partner_id'] || !$auth['partner_key']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return $this->toSettingsTab('explorer')->with('shopee_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => "Partner ID and Partner Key for {$modeLabel} mode are required."],
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
                return $this->toSettingsTab('explorer')->withInput()->with('shopee_result', [
                    'ok' => false,
                    'title' => 'Invalid JSON',
                    'data' => ['message' => $e->getMessage()],
                ]);
            }
        }

        $timestamp = time();
        $accessToken = ($useAccessToken && $auth['access_token']) ? (string)$auth['access_token'] : null;
        $shopId = ($useShopId && $auth['shop_id']) ? (int)$auth['shop_id'] : null;

        if ($accessToken && $shopId) {
            $sign = $client->signShop((int)$auth['partner_id'], (string)$auth['partner_key'], $path, $timestamp, $accessToken, $shopId);
        } else {
            $sign = $client->signAuth((int)$auth['partner_id'], (string)$auth['partner_key'], $path, $timestamp);
        }

        $query = [
            'partner_id' => (int)$auth['partner_id'],
            'timestamp' => $timestamp,
            'sign' => $sign,
        ];

        if ($accessToken !== null) {
            $query['access_token'] = $accessToken;
        }
        if ($shopId !== null) {
            $query['shop_id'] = $shopId;
        }

        $body = [];
        if ($data['method'] === 'GET') {
            $query = array_merge($query, $customParams);
        } else {
            $body = $customParams;
        }

        $result = $data['method'] === 'POST'
            ? $client->postJson($auth['mode'], $path, $query, $body)
            : $client->get($auth['mode'], $path, $query);

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

        return $this->toSettingsTab('explorer')->with('shopee_result', [
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

        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = ShopeeStore::activeAuth($store);
        if (!$store || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return $this->toSettingsTab('explorer')->with('shopee_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => "Partner ID, Partner Key, Access Token, and Shop ID for {$modeLabel} mode are required for preset packs."],
            ]);
        }

        $language = $auth['region'] ?: 'en';

        $packs = [
            'shop_info' => [
                ['GET', '/api/v2/shop/get_shop_info', []],
                ['GET', '/api/v2/shop/get_profile', []],
            ],
            'catalog' => [
                ['GET', '/api/v2/product/get_category', ['language' => $language]],
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
                ['GET', '/api/v2/product/get_category', ['language' => $language]],
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
                    $auth['mode'],
                    (int)$auth['partner_id'],
                    (string)$auth['partner_key'],
                    (string)$auth['access_token'],
                    (int)$auth['shop_id'],
                    $path,
                    $extra
                )
                : $client->shopGet(
                    $auth['mode'],
                    (int)$auth['partner_id'],
                    (string)$auth['partner_key'],
                    (string)$auth['access_token'],
                    (int)$auth['shop_id'],
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

        return $this->toSettingsTab('explorer')->with('shopee_result', [
            'ok' => collect($results)->every(fn($r) => $r['ok']),
            'title' => 'Preset Pack: ' . strtoupper($data['pack']),
            'data' => $results,
        ]);
    }

}
