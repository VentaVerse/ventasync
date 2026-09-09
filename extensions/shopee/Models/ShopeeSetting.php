<?php

namespace Extensions\shopee\Models;

use Illuminate\Database\Eloquent\Model;

class ShopeeSetting extends Model
{
    /**
     * How long a Shopee refresh token lives from the moment it is granted.
     * Shopee's token endpoint reports only the ACCESS token's life (four
     * hours, expire_in), so the refresh token's end is computed here at each
     * grant; it is the date a person has to sign in again.
     */
    public const REFRESH_TOKEN_DAYS = 30;

    protected $table = 'shopee_settings';

    protected $fillable = [
        'store_name',
        'enabled',
        'mode',
        'partner_id',
        'partner_key',
        'shop_id',
        'access_token',
        'refresh_token',
        'expires_at',
        'refresh_expires_at',
        'redirect_uri',
        'region',
        'sync_last_days',
        'sync_last_days_returns',
        'api_log_mode',
        'last_order_sync_at',
        'last_stock_push_at',
        'last_return_sync_at',
        'last_review_sync_at',
        'sandbox_partner_id',
        'sandbox_partner_key',
        'sandbox_shop_id',
        'sandbox_access_token',
        'sandbox_refresh_token',
        'sandbox_expires_at',
        'sandbox_refresh_expires_at',
        'sandbox_redirect_uri',
        'sandbox_region',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'expires_at' => 'datetime',
        'refresh_expires_at' => 'datetime',
        'sandbox_expires_at' => 'datetime',
        'sandbox_refresh_expires_at' => 'datetime',
        'last_order_sync_at' => 'datetime',
        'last_stock_push_at' => 'datetime',
        'last_return_sync_at' => 'datetime',
        'last_review_sync_at' => 'datetime',
    ];

    /**
     * The store a storeless read acts for (multi-store S1, #194): the
     * first enabled row - which, until S4 threads {store} through the
     * routes, is the same single row the old singleton reads returned.
     *
     * Every former ShopeeSetting::defaultStore() call goes through
     * here so S4 re-aims ONE name at the route's store instead of
     * hunting 89 spellings. New code that already knows its store
     * should take a ShopeeSetting instance, not call this.
     */
    public static function defaultStore(): ?self
    {
        // The store the request is standing in (bound by ResolveShopeeStore
        // from the {store} route segment) outranks the first-enabled
        // fallback: on a store page, "the store" is never ambiguous.
        if (app()->bound('shopee.route-store')) {
            return app('shopee.route-store');
        }

        return static::query()->where('enabled', true)->orderBy('id')->first();
    }

    /**
     * Keep URL generation able to fill the {store} segment (S4, #194)
     * anywhere a link to a Shopee page is built OUTSIDE a Shopee store
     * request - the channels hub, the fulfilment tabs, test code. The
     * middleware overrides this per-request with the route's own store.
     */
    public static function syncUrlDefault(): void
    {
        /* Never clobber the store this request is acting for (the owner, live
         * 2026-09-07). This runs on every created/updated/deleted, so
         * SAVING the second store's settings re-pointed URL generation at
         * the FIRST enabled store, and the redirect that followed landed on
         * store one. The bound store is the request's truth; this fallback
         * exists only for pages built outside a store request. */
        if (app()->bound('shopee.route-store')) {
            return;
        }

        try {
            $id = static::query()->where('enabled', true)->orderBy('id')->value('id');
        } catch (\Throwable $e) {
            return; // no table yet (fresh install mid-migrate)
        }
        if ($id !== null) {
            \Illuminate\Support\Facades\URL::defaults(['store' => (int) $id]);
        }
    }

    protected static function booted(): void
    {
        static::created(fn () => static::syncUrlDefault());
        static::updated(fn () => static::syncUrlDefault());
        static::deleted(fn () => static::syncUrlDefault());
    }

    /**
     * Return a read-only decrypted copy of this setting.
     */
    public function decrypted(): object
    {
        $s = (object) $this->toArray();

        foreach (['partner_key', 'access_token', 'refresh_token', 'sandbox_partner_key', 'sandbox_access_token', 'sandbox_refresh_token'] as $k) {
            if (!empty($s->$k)) {
                try {
                    $s->$k = decrypt($s->$k);
                } catch (\Throwable $e) {
                    // Leave as-is if decrypt fails (plain text from old installs)
                }
            }
        }

        return $s;
    }

    /**
     * Pick the credentials/tokens the active mode wants. Shopee stores live
     * and sandbox creds in separate columns; reading $setting->partner_id
     * directly silently breaks every sandbox flow. Every call site that
     * authenticates against Shopee should pull through this helper.
     *
     * Returns: mode, partner_id, partner_key, shop_id, access_token,
     * refresh_token, region, expires_at, complete.
     * Mirrors the Lazada activeCredentials() shape from a28c2d8.
     *
     * Expects the decrypted() result. Passing a raw model returns ciphertext.
     */
    /**
     * The buyer-facing URL of a listed item - the answer to "what does the
     * buyer see right now?" after a push (critique P2, 2026-08-25). Region
     * defaults to ph; a sandbox item's URL will not resolve publicly, which
     * is the sandbox being a sandbox, not the link being wrong.
     */
    public static function publicListingUrl(int|string $itemId): ?string
    {
        // Request-scoped memo (50 ids per index page must not mean 50
        // settings queries), never process-scoped: a static would leak a
        // stale shop across tests and long-lived workers.
        $base = request()->attributes->get('shopee.listing-url-base', false);
        if ($base === false) {
            $setting = static::defaultStore()?->decrypted();
            $auth = static::activeAuth($setting);
            $region = strtolower(trim((string) ($setting->region ?? ''))) ?: 'ph';
            $base = !empty($auth['shop_id'])
                ? 'https://shopee.' . $region . '/product/' . (int) $auth['shop_id']
                : null;
            request()->attributes->set('shopee.listing-url-base', $base);
        }

        return $base !== null ? $base . '/' . $itemId : null;
    }

    public static function activeAuth(?object $setting): array
    {
        if (!$setting) {
            return [
                'mode'          => 'sandbox',
                'store_id'      => null,
                'partner_id'    => null,
                'partner_key'   => null,
                'shop_id'       => null,
                'access_token'  => null,
                'refresh_token' => null,
                'region'        => null,
                'expires_at'    => null,
                'complete'      => false,
            ];
        }

        $mode = $setting->mode ?? 'sandbox';
        $isSandbox = $mode === 'sandbox';

        $partnerId    = $isSandbox ? ($setting->sandbox_partner_id    ?? null) : ($setting->partner_id    ?? null);
        $partnerKey   = $isSandbox ? ($setting->sandbox_partner_key   ?? null) : ($setting->partner_key   ?? null);
        $shopId       = $isSandbox ? ($setting->sandbox_shop_id       ?? null) : ($setting->shop_id       ?? null);
        $accessToken  = $isSandbox ? ($setting->sandbox_access_token  ?? null) : ($setting->access_token  ?? null);
        $refreshToken = $isSandbox ? ($setting->sandbox_refresh_token ?? null) : ($setting->refresh_token ?? null);
        $region       = $isSandbox ? ($setting->sandbox_region        ?? null) : ($setting->region        ?? null);
        $expiresAt    = $isSandbox ? ($setting->sandbox_expires_at    ?? null) : ($setting->expires_at    ?? null);

        return [
            'mode'          => $mode,
            /* OUR store's id, beside Shopee's shop id, because a caller
               holding auth is acting FOR one store and anything it writes
               has to be fenced to that store. The console has no read
               fence (BelongsToShopeeStore), so a cron that could not name
               its store wrote across all of them. */
            'store_id'      => isset($setting->id) ? (int) $setting->id : null,
            'partner_id'    => $partnerId,
            'partner_key'   => $partnerKey,
            'shop_id'       => $shopId,
            'access_token'  => $accessToken  ? (string) $accessToken  : null,
            'refresh_token' => $refreshToken ? (string) $refreshToken : null,
            'region'        => $region,
            'expires_at'    => $expiresAt,
            'complete'      => !empty($partnerId) && !empty($partnerKey) && !empty($accessToken) && !empty($shopId),
        ];
    }
}
