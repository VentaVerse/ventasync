<?php

namespace Extensions\shopee\Controllers;

use App\Http\Controllers\Controller;

use Extensions\shopee\Models\ShopeeApiLog;
use Extensions\shopee\Models\ShopeeOrderStatusMap;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\Services\Shopee\ShopeeClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ShopeeController extends Controller
{
    use \App\Http\Controllers\Concerns\ReturnsToSettingsTab;

    /** The settings page of the store this request stands in, for ReturnsToSettingsTab. */
    protected function settingsTabRoute(): array
    {
        $store = ShopeeSetting::defaultStore();

        return $store ? ['ext.shopee.settings.show', ['store' => $store->id]] : ['ext.shopee.index', []];
    }

public function root(Request $request, ShopeeClient $client)
{
    // Shopee may only allow a domain-level redirect URI (the app's public APP_URL).
    // When redirected here with ?code=...&shop_id=..., we exchange tokens immediately and persist them.

    $code = $request->query('code');
    $shopId = $request->query('shop_id');

    if (!$code && !$shopId) {
        return redirect()->route('dashboard');
    }

    $setting = ShopeeSetting::defaultStore()?->decrypted();
    $mode = $setting->mode ?? 'sandbox';

    // Persist shop_id to the env-appropriate column.
    if ($shopId) {
        $raw = ShopeeSetting::defaultStore() ?? new ShopeeSetting();
        $this->persistShopId($raw, $mode, (int)$shopId);
        // Reload so the activeAuth() check below sees the just-saved shop_id.
        $setting = ShopeeSetting::defaultStore()?->decrypted();
    }

    $auth = $this->activeAuth($setting);

    // If we have everything required, perform token exchange automatically
    if ($code && $setting && $auth['partner_id'] && $auth['partner_key'] && $auth['shop_id']) {
        $result = $client->exchangeToken(
            $auth['mode'],
            (int)$auth['partner_id'],
            (string)$auth['partner_key'],
            (string)$code,
            (int)$auth['shop_id']
        );

        // Persist tokens if present
        if ($result['ok'] && is_array($result['body'])) {
            $access = $result['body']['access_token'] ?? null;
            $refresh = $result['body']['refresh_token'] ?? null;

            if ($access) {
                $raw = ShopeeSetting::defaultStore();
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

    // Fallback: show code + shop_id so user can complete token exchange manually in the Shopee page
    return view('ext-shopee::callback', [
        'code' => $code,
        'shop_id' => $shopId,
    ]);
}

public function index(Request $request = null, ShopeeClient $client = null)
    {
        $request = $request ?? request();
        $client = $client ?? app(ShopeeClient::class);

        // Auto-exchange on OAuth callback. Shopee redirects to our
        // computedRedirectUri() which is `route('ext.shopee.index')`, so the
        // /shopee URL is what receives ?code=...&shop_id=.... If those params
        // are present and we have credentials for the active env, exchange
        // immediately and persist via the env-aware helpers below.
        $cbCode = (string) $request->query('code', '');
        $cbShopId = (string) $request->query('shop_id', '');
        if ($cbCode !== '' || $cbShopId !== '') {
            /* Whose grant is this (S4b, #194)? The store that started the
             * consent flow, else the store already holding this shop_id,
             * else the default. Bound for the request so every
             * defaultStore() read below - persistShopId, the exchange, the
             * token write - lands on the SAME row: a grant must never
             * split across stores. */
            $rawSetting = ($id = cache()->pull('shopee_oauth_store')) !== null
                ? ShopeeSetting::query()->find($id)
                : null;
            if ($rawSetting === null && $cbShopId !== '') {
                $rawSetting = ShopeeSetting::query()
                    ->where('shop_id', (int) $cbShopId)
                    ->orWhere('sandbox_shop_id', (int) $cbShopId)
                    ->orderBy('id')->first();
            }
            $rawSetting ??= ShopeeSetting::defaultStore();
            if ($rawSetting !== null) {
                app()->instance('shopee.route-store', $rawSetting);
            }
            $rawSetting ??= new ShopeeSetting();
            $mode = $rawSetting->mode ?? 'sandbox';
            if ($cbShopId !== '') {
                $this->persistShopId($rawSetting, $mode, (int) $cbShopId);
            }
            $dec = ShopeeSetting::defaultStore()?->decrypted();
            $auth = $this->activeAuth($dec);
            if ($cbCode !== '' && $auth['partner_id'] && $auth['partner_key'] && $auth['shop_id']) {
                $result = $client->exchangeToken(
                    $auth['mode'],
                    (int) $auth['partner_id'],
                    (string) $auth['partner_key'],
                    $cbCode,
                    (int) $auth['shop_id']
                );
                if (($result['ok'] ?? false) && is_array($result['body'] ?? null)) {
                    $access = $result['body']['access_token'] ?? null;
                    if ($access) {
                        $raw = ShopeeSetting::defaultStore();
                        if ($raw) {
                            $this->persistTokens(
                                $raw,
                                $auth['mode'],
                                (string) $access,
                                $result['body']['refresh_token'] ?? null,
                                $result['body']['expire_in'] ?? null
                            );
                        }
                    }
                }
                // Strip the OAuth params from the URL so a refresh doesn't
                // re-exchange a stale (one-time-use) code. The store whose
                // grant this was shows the result on ITS settings page.
                $landing = ShopeeSetting::defaultStore();

                return ($landing !== null
                        ? redirect()->route('ext.shopee.settings.show', ['store' => $landing->id])
                        : redirect()->route('ext.shopee.index'))
                    ->with('shopee_result', [
                        'ok'    => (bool) ($result['ok'] ?? false),
                        'title' => 'OAuth callback — token exchange',
                        'data'  => $result,
                    ]);
            }
        }

        /* Multi-store S4 (#194): /shopee is the OAuth landing (its URI is
         * registered byte-for-byte in the Open Platform console) and
         * nothing else any more. A plain visit goes where Venta's does:
         * the first store's settings page, or the module page when no
         * store exists yet to configure. */
        $first = ShopeeSetting::query()->orderBy('id')->first();
        if ($first !== null) {
            return redirect()->route('ext.shopee.settings.show', ['store' => $first->id]);
        }

        return redirect()->route('channels.module', ['module' => 'shopee']);
    }

    /**
     * The per-store settings page (multi-store S4, #194): everything the
     * storeless /shopee page used to render, for the store the route
     * names. ResolveShopeeStore has already bound that store, so every
     * defaultStore() read below - and in the partials - answers with it.
     */
    public function showSettings(Request $request)
    {
        $setting = ShopeeSetting::defaultStore();

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $orderStatusMap = ShopeeOrderStatusMap::where('context', 'order')->pluck('order_status_id', 'shopee_status')->all();

        $erpOrderStatuses = DB::table($pfx . 'order_status')
            ->where('language_id', $langId)
            ->orderBy('order_status_id')
            ->get(['order_status_id', 'name']);

        $shopeeStatuses = [
            'UNPAID'             => 'Unpaid',
            'READY_TO_SHIP'      => 'Ready to Ship',
            'PROCESSED'          => 'Processed',
            'RETRY_SHIP'         => 'Retry Ship',
            'SHIPPED'            => 'Shipped',
            'TO_CONFIRM_RECEIVE' => 'Delivered',
            'COMPLETED'          => 'Completed',
            'IN_CANCEL'          => 'In Cancel',
            'CANCELLED'          => 'Cancelled',
            'TO_RETURN'          => 'To Return',
        ];

        $shopeeReturnStatuses = [
            'REQUESTED'           => 'Requested',
            'ACCEPTED'            => 'Accepted',
            'CANCELLED'           => 'Cancelled',
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

        // #200: the shared log panel - errors-only and path filters, paged.
        $logs = \App\Support\ApiLogPanel::paginate(
            ShopeeApiLog::query(), 'api_path', fn ($q) => $q->where('ok', 0)
        );

        return view('ext-shopee::index', [
            'setting' => $setting?->decrypted(),
            'defaultRedirect' => $this->computedRedirectUri(),
            'result' => session('shopee_result'),
            'orderStatusMap' => $orderStatusMap,
            'erpOrderStatuses' => $erpOrderStatuses,
            'shopeeStatuses' => $shopeeStatuses,
            'shopeeReturnStatuses' => $shopeeReturnStatuses,
            'returnStatusMap' => $returnStatusMap,
            'logs' => $logs,
        ]);
    }

    /**
     * The store's own name and switch (multi-store), on their own form: not
     * credentials of either environment, so they sit under Environment on
     * the settings page, visible whichever environment the store stands in.
     * The pre-multistore store ran in sandbox and never saw them inside the
     * production panel (the owner, 2026-09-05). Deliberately not save(): that
     * one also rewrites the mode and the region.
     */
    public function saveStore(Request $request)
    {
        $data = $request->validate([
            'store_name' => 'required|string|max:128',
            'enabled' => 'required|boolean',
        ]);
        $setting = ShopeeSetting::defaultStore();
        if (!$setting) {
            return $this->toSettingsTab('connection')->with('error', 'Shopee settings not found.');
        }
        $setting->update(['store_name' => $data['store_name'], 'enabled' => (bool) $data['enabled']]);

        return $this->toSettingsTab('connection')
            ->with('status', $data['store_name'] . ' is ' . ((bool) $data['enabled'] ? 'active.' : 'paused. Nothing syncs until it is switched back on.'));
    }

    public function save(Request $request)
    {
        $data = $request->validate([
            'env' => 'nullable|in:live,sandbox',
            'partner_id' => 'nullable|integer|min:1',
            'partner_key' => 'nullable|string',
            'shop_id' => 'nullable|integer|min:1',
            'access_token' => 'nullable|string',
            'region' => 'nullable|in:sg,my,th,vn,id,ph,tw,br,mx,co,cl,pl',
            'sandbox_partner_id' => 'nullable|integer|min:1',
            'sandbox_partner_key' => 'nullable|string',
            'sandbox_shop_id' => 'nullable|integer|min:1',
            'sandbox_access_token' => 'nullable|string',
            'sandbox_region' => 'nullable|in:sg,my,th,vn,id,ph,tw,br,mx,co,cl,pl',
            'store_name' => 'nullable|string|max:128',
            'enabled' => 'nullable|boolean',
        ]);

        $setting = ShopeeSetting::defaultStore() ?? new ShopeeSetting();

        /* The store's own identity (S4b, #194): its name, and whether the
         * crons and tabs treat it as live. Sent only by forms that carry
         * the fields, so older callers change nothing. */
        if (array_key_exists('store_name', $data) && $data['store_name'] !== null) {
            $setting->store_name = $data['store_name'];
        }
        if (array_key_exists('enabled', $data) && $data['enabled'] !== null) {
            $setting->enabled = (bool) $data['enabled'];
        }

        $env = $data['env'] ?? 'live';
        $setting->mode = $env;

        if ($env === 'live') {
            // Credential fields are masked in the form (value="" + placeholder).
            // A blank submission means "keep existing" — never wipe stored creds
            // just because the user re-saved the form without typing them in.
            // Region is a dropdown; a blank selection is the explicit "(not set)" choice.
            if (!empty($data['partner_id']))    $setting->partner_id = $data['partner_id'];
            if (!empty($data['shop_id']))       $setting->shop_id = $data['shop_id'];
            if (!empty($data['partner_key']))   $setting->partner_key = encrypt(trim($data['partner_key']));
            if (!empty($data['access_token']))  $setting->access_token = encrypt(trim($data['access_token']));
            $setting->region = $data['region'] ?? null;
        } else {
            if (!empty($data['sandbox_partner_id']))    $setting->sandbox_partner_id = $data['sandbox_partner_id'];
            if (!empty($data['sandbox_shop_id']))       $setting->sandbox_shop_id = $data['sandbox_shop_id'];
            if (!empty($data['sandbox_partner_key']))   $setting->sandbox_partner_key = encrypt(trim($data['sandbox_partner_key']));
            if (!empty($data['sandbox_access_token']))  $setting->sandbox_access_token = encrypt(trim($data['sandbox_access_token']));
            $setting->sandbox_region = $data['sandbox_region'] ?? null;
        }

        $setting->save();

        $label = $env === 'sandbox' ? 'Sandbox' : 'Production';
        return $this->redirectToSettings()->with('status', "{$label} settings saved.");
    }

    public function toggleMode(Request $request)
    {
        $data = $request->validate(['mode' => 'required|in:live,sandbox']);
        $setting = ShopeeSetting::defaultStore() ?? new ShopeeSetting();
        $setting->mode = $data['mode'];
        $setting->save();

        $label = $data['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
        return $this->redirectToSettings()->with('status', "Switched to {$label} mode.");
    }

    public function callback(Request $request)
    {
        // Shopee returns code + shop_id (and possibly main_account_id for some setups)
        $code = $request->query('code');
        $shopId = $request->query('shop_id');

        // Store shop_id in the env-appropriate column.
        if ($shopId) {
            $raw = ShopeeSetting::defaultStore() ?? new ShopeeSetting();
            $this->persistShopId($raw, $raw->mode ?? 'sandbox', (int)$shopId);
        }

        return view('ext-shopee::callback', [
            'code' => $code,
            'shop_id' => $shopId,
        ]);
    }

    /**
     * Pick the credentials for the currently-selected mode.
     * Shopee settings store live + sandbox credentials independently;
     * authorize, buildAuthUrl, and token exchange all need the active set.
     */
    private function activeCredentials(?object $setting): array
    {
        $redirect = $this->computedRedirectUri();
        if (!$setting) {
            return ['partner_id' => null, 'partner_key' => null, 'redirect_uri' => $redirect, 'mode' => 'sandbox'];
        }
        $mode = $setting->mode ?? 'sandbox';
        if ($mode === 'sandbox') {
            return [
                'partner_id'   => $setting->sandbox_partner_id ?? null,
                'partner_key'  => $setting->sandbox_partner_key ?? null,
                'redirect_uri' => $redirect,
                'mode'         => 'sandbox',
            ];
        }
        return [
            'partner_id'   => $setting->partner_id ?? null,
            'partner_key'  => $setting->partner_key ?? null,
            'redirect_uri' => $redirect,
            'mode'         => 'live',
        ];
    }

    /**
     * Full env-aware credential bundle for a token-exchange / token-refresh call.
     * Extends activeCredentials() with shop_id + tokens, all read from the
     * env-appropriate columns (live vs sandbox_*). Returns null partner_id etc
     * when the column is empty, so callers can validate up front.
     */
    private function activeAuth(?object $setting): array
    {
        if (!$setting) {
            return [
                'mode'          => 'sandbox',
                'partner_id'    => null,
                'partner_key'   => null,
                'shop_id'       => null,
                'access_token'  => null,
                'refresh_token' => null,
            ];
        }
        $mode = $setting->mode ?? 'sandbox';
        $isSandbox = $mode === 'sandbox';
        return [
            'mode'          => $mode,
            'partner_id'    => $isSandbox ? ($setting->sandbox_partner_id    ?? null) : ($setting->partner_id    ?? null),
            'partner_key'   => $isSandbox ? ($setting->sandbox_partner_key   ?? null) : ($setting->partner_key   ?? null),
            'shop_id'       => $isSandbox ? ($setting->sandbox_shop_id       ?? null) : ($setting->shop_id       ?? null),
            'access_token'  => $isSandbox ? ($setting->sandbox_access_token  ?? null) : ($setting->access_token  ?? null),
            'refresh_token' => $isSandbox ? ($setting->sandbox_refresh_token ?? null) : ($setting->refresh_token ?? null),
        ];
    }

    /**
     * Persist freshly-exchanged tokens to the right pair of columns. Encrypts
     * access_token + refresh_token, and computes expires_at from expire_in.
     * Mirrors the Lazada activeCredentials/persist pattern from a28c2d8 so
     * sandbox-mode auth never spills tokens into the live columns.
     */
    private function persistTokens(ShopeeSetting $raw, string $mode, string $access, ?string $refresh, $expireIn): void
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
                $raw->sandbox_refresh_expires_at = now()->addDays(ShopeeSetting::REFRESH_TOKEN_DAYS);
            }
        } else {
            $raw->access_token = encrypt($access);
            if ($refresh) {
                $raw->refresh_token = encrypt($refresh);
                // Shopee does not say when the refresh token dies: it is 30
                // days from each grant, so the sign-in expiry is stamped here.
                $raw->refresh_expires_at = now()->addDays(ShopeeSetting::REFRESH_TOKEN_DAYS);
            }
            if (is_numeric($expireIn)) {
                $raw->expires_at = now()->addSeconds((int)$expireIn);
            }
        }
        $raw->save();
    }

    /**
     * Save the shop_id returned by Shopee's callback to the right column.
     * Sandbox callback flow returns the sandbox shop_id; in live mode it's
     * the live shop_id. Routing both into a single shop_id column was the
     * cause of sandbox auth flow overwriting live credentials.
     */
    private function persistShopId(ShopeeSetting $raw, string $mode, int $shopId): void
    {
        if ($mode === 'sandbox') {
            $raw->sandbox_shop_id = $shopId;
        } else {
            $raw->shop_id = $shopId;
        }
        $raw->save();
    }

    /**
     * Redirect URI we send to Shopee and display to the operator. Derived
     * from APP_URL with HTTPS forced and pointed at the Shopee settings page
     * — Shopee bounces the user back with `?code=...&shop_id=...` in the URL,
     * so landing them on the settings page (which has the code-paste form
     * in plain sight) is the right UX. Read-only in the UI prevents typos
     * silently breaking OAuth, since the URL must byte-match what's
     * registered in the Shopee Open Platform console.
     */
    /**
     * A new Shopee store (S4b, #194): a named, disabled row the operator
     * then dresses on its own settings page - credentials, mode, OAuth.
     * Venta's createStore, in Shopee's anatomy.
     */
    public function createStore(Request $request)
    {
        $data = $request->validate([
            'store_name' => ['required', 'string', 'max:128'],
        ]);

        $setting = ShopeeSetting::create([
            'store_name' => $data['store_name'],
            'enabled' => false,
            'mode' => 'live',
        ]);

        \App\Services\ActivityLogger::log('created', 'Shopee Store', $setting->id, $setting->store_name);

        return redirect()
            ->route('ext.shopee.settings.show', ['store' => $setting->id])
            ->with('status', 'Store created. Add the partner credentials and authorize it to start syncing.');
    }

    /**
     * Where a settings write lands (multi-store S4, #194): the settings
     * page of the store it acted on. The storeless name survives only as
     * the no-store fallback, where it forwards to the module page.
     */
    private function redirectToSettings()
    {
        $store = ShopeeSetting::defaultStore();

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
    /* Which store is consenting (S4b, #194): named by ?store= from the
     * store's own settings page, else the default. Remembered for the
     * callback, because Shopee's redirect URI is one fixed URL for the
     * whole install and the answer arrives with no other context. */
    $store = request()->query('store')
        ? ShopeeSetting::query()->find((int) request()->query('store'))
        : ShopeeSetting::defaultStore();
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
        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $creds = $this->activeCredentials($setting);

        if (!$setting || !$creds['partner_id'] || !$creds['partner_key']) {
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

    public function tokenGet(Request $request, ShopeeClient $client)
    {
        $data = $request->validate([
            'code' => 'required|string',
        ]);

        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = $this->activeAuth($setting);
        if (!$setting || !$auth['partner_id'] || !$auth['partner_key'] || !$auth['shop_id']) {
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

        // Persist tokens if present
        if ($result['ok'] && is_array($result['body'])) {
            $access = $result['body']['access_token'] ?? null;
            $refresh = $result['body']['refresh_token'] ?? null;

            if ($access) {
                $raw = ShopeeSetting::defaultStore();
                if ($raw) {
                    $this->persistTokens($raw, $auth['mode'], (string)$access, $refresh, $result['body']['expire_in'] ?? null);
                }
            }
        }

        return $this->redirectToSettings()->with('shopee_result', [
            'ok' => (bool)$result['ok'],
            'title' => 'Token Get',
            'data' => $result,
        ]);
    }

    public function tokenRefresh(ShopeeClient $client)
    {
        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = $this->activeAuth($setting);
        if (!$setting || !$auth['partner_id'] || !$auth['partner_key'] || !$auth['shop_id'] || !$auth['refresh_token']) {
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

        if ($result['ok'] && is_array($result['body'])) {
            $access = $result['body']['access_token'] ?? null;
            $refresh = $result['body']['refresh_token'] ?? null;

            if ($access) {
                $raw = ShopeeSetting::defaultStore();
                if ($raw) {
                    $this->persistTokens($raw, $auth['mode'], (string)$access, $refresh, $result['body']['expire_in'] ?? null);
                }
                if ($raw) {
                    Cache::forget('shopee_sync_paused:' . $raw->id);
                }
                Cache::forget('shopee_sync_paused'); // legacy pre-store key
            }
        }

        return $this->redirectToSettings()->with('shopee_result', [
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

        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['partner_id'] || !$auth['partner_key']) {
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
            ? $client->postJson($setting->mode, $path, $query, $body)
            : $client->get($setting->mode, $path, $query);

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

    /** The three-way log mode (#200): everything / errors only / off. */
    public function setApiLogMode(Request $request)
    {
        $data = $request->validate(['mode' => 'required|in:' . implode(',', \App\Support\ApiLogMode::MODES)]);
        $setting = ShopeeSetting::defaultStore();
        if (!$setting) {
            return $this->toSettingsTab('logs')->with('error', 'Shopee settings not found.');
        }

        $setting->update(['api_log_mode' => $data['mode']]);

        return $this->toSettingsTab('logs')
            ->with('status', 'Shopee API log now records: ' . \App\Support\ApiLogMode::labels()[$data['mode']] . '.');
    }

    public function clearApiLogs()
    {
        $count = ShopeeApiLog::query()->count();
        // This store's rows only: the global scope holds the store, and a
        // truncate would have wiped every store's record (2026-09-05).
        ShopeeApiLog::query()->delete();

        return $this->toSettingsTab('logs')->with('status', "Deleted {$count} Shopee API log entries.");
    }

    /**
     * Null the `raw` API-response payload on Shopee order tables for rows
     * older than N days. The synced order data itself is left intact —
     * only the verbose raw blob is cleared, freeing DB space.
     *
     * Moved here from SettingController::purgeRaw so each integration
     * owns its own table list and a low-permission user can't trigger
     * Shopee-wide deletes via a generic Settings page action.
     */
    public function purgeRaw(Request $request)
    {
        // This store's rows only, on every table that carries the store (2026-09-05).
        $storeId = app()->bound('shopee.route-store') ? (int) app('shopee.route-store')->id : null;
        $request->validate([
            'days' => ['required', 'integer', 'min:1', 'max:3650'],
        ]);

        $cutoff = now()->subDays((int) $request->days);
        $tables = [
            'shopee_orders',
            'shopee_order_products',
            'shopee_returns',
        ];

        $total = 0;
        foreach ($tables as $table) {
            $total += DB::table($table)
                ->when($storeId !== null && \Illuminate\Support\Facades\Schema::hasColumn($table, 'shopee_setting_id'), fn ($q) => $q->where('shopee_setting_id', $storeId))
                ->whereNotNull('raw')
                ->where('created_at', '<', $cutoff)
                ->update(['raw' => null]);
        }

        \App\Services\ActivityLogger::log('purged', 'ShopeeSetting', null, "Raw data ({$request->days} days)");

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

        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['partner_id'] || !$auth['partner_key']) {
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

        // For GET requests, merge custom params into query
        // For POST requests, they go into body
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

        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
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

    public function logisticsChannels(ShopeeClient $client)
    {
        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return $this->toSettingsTab('connection')->with('shopee_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => "Partner ID, Partner Key, Access Token, and Shop ID for {$modeLabel} mode are required."],
            ]);
        }

        $path = '/api/v2/logistics/get_channel_list';

        $result = $client->shopGet(
            $auth['mode'],
            (int)$auth['partner_id'],
            (string)$auth['partner_key'],
            (string)$auth['access_token'],
            (int)$auth['shop_id'],
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

        return $this->toSettingsTab('connection')->with('shopee_result', [
            'ok' => (bool)$result['ok'],
            'title' => 'Logistics Channels',
            'data' => $result,
        ]);
    }

}
