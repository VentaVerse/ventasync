<?php

namespace Extensions\tiktok\Models;

use Illuminate\Database\Eloquent\Model;

class TikTokSetting extends Model
{
    protected $table = 'tiktok_settings';

    protected $fillable = [
        'store_name',
        'enabled',
        'mode',
        'app_key',
        'app_secret',
        'access_token',
        'refresh_token',
        'expires_at',
        'refresh_expires_at',
        'shop_id',
        'shop_cipher',
        'shop_code',
        'shop_name',
        'warehouse_id',
        'redirect_uri',
        'region',
        'sync_last_days',
        'sync_last_days_returns',
        'order_tab_map',
        'api_log_mode',
        'last_order_sync_at',
        'last_stock_push_at',
        'sandbox_app_key',
        'sandbox_app_secret',
        'sandbox_access_token',
        'sandbox_refresh_token',
        'sandbox_expires_at',
        'sandbox_refresh_expires_at',
        'sandbox_shop_id',
        'sandbox_shop_cipher',
        'sandbox_shop_code',
        'sandbox_shop_name',
        'sandbox_warehouse_id',
        'sandbox_redirect_uri',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'order_tab_map' => 'array',
        'expires_at' => 'datetime',
        'refresh_expires_at' => 'datetime',
        'sandbox_expires_at' => 'datetime',
        'sandbox_refresh_expires_at' => 'datetime',
        'last_order_sync_at' => 'datetime',
        'last_stock_push_at' => 'datetime',
    ];

    /**
     * The store a storeless read acts for (multi-store, the owner 2026-09-05): the
     * store the request is standing in (bound by ResolveTikTokStore from the
     * {store} route segment) when there is one, else the first enabled store.
     * Every former storeless settings read goes through here, so a
     * store page gets the right row and a storeless context (the OAuth
     * callback, a cron command) keeps the old first-enabled behaviour. Mirrors
     * LazadaSetting::defaultStore.
     */
    public static function defaultStore(): ?self
    {
        if (app()->bound('tiktok.route-store')) {
            return app('tiktok.route-store');
        }

        return static::query()->where('enabled', true)->orderBy('id')->first();
    }

    /** Every store, enabled or not - the Integrations card's store list. */
    public static function allStores(): \Illuminate\Support\Collection
    {
        return static::query()->orderBy('id')->get();
    }

    /** Every enabled store - what the sync and cron paths iterate. */
    public static function enabledStores(): \Illuminate\Support\Collection
    {
        return static::query()->where('enabled', true)->orderBy('id')->get();
    }

    /**
     * Keep URL generation able to fill the {store} segment anywhere a link to
     * a TikTok page is built OUTSIDE a TikTok store request - the channels hub,
     * the fulfilment tabs, the OAuth callback view, test code.
     * ResolveTikTokStore overrides this per-request with the route's own store.
     */
    public static function syncUrlDefault(): void
    {
        /* Never clobber the store this request is acting for (the owner, live
         * 2026-09-07). This runs on every created/updated/deleted, so
         * SAVING the second store's settings re-pointed URL generation at
         * the FIRST enabled store, and the redirect that followed landed on
         * store one. The bound store is the request's truth; this fallback
         * exists only for pages built outside a store request. */
        if (app()->bound('tiktok.route-store')) {
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
     * Credentials for whichever mode the shop runs in, or null when any
     * part is missing. The same four keys the controllers' creds() build.
     *
     * @return array{app_key: string, app_secret: string, token: string, shop_cipher: string}|null
     */
    public static function credentials(): ?array
    {
        $s = static::defaultStore();
        if (!$s) {
            return null;
        }
        $d = $s->decrypted();
        $sandbox = $s->mode === 'sandbox';
        $c = [
            'app_key' => (string) ($sandbox ? ($d->sandbox_app_key ?? '') : ($d->app_key ?? '')),
            'app_secret' => (string) ($sandbox ? ($d->sandbox_app_secret ?? '') : ($d->app_secret ?? '')),
            'token' => (string) ($sandbox ? ($d->sandbox_access_token ?? '') : ($d->access_token ?? '')),
            'shop_cipher' => (string) ($sandbox ? ($s->sandbox_shop_cipher ?? '') : ($s->shop_cipher ?? '')),
        ];

        return ($c['app_key'] === '' || $c['app_secret'] === '' || $c['token'] === '') ? null : $c;
    }

    public function decrypted(): object
    {
        $s = (object) $this->toArray();

        foreach (['app_secret', 'access_token', 'refresh_token', 'sandbox_app_secret', 'sandbox_access_token', 'sandbox_refresh_token'] as $k) {
            if (!empty($s->$k)) {
                try {
                    $s->$k = decrypt($s->$k);
                } catch (\Throwable $e) {
                    // Leave as-is if decrypt fails
                }
            }
        }

        return $s;
    }
}
