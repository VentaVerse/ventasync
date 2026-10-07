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

    public static function defaultStore(): ?self
    {
        if (app()->bound('tiktok.route-store')) {
            return app('tiktok.route-store');
        }

        return static::query()->where('enabled', true)->orderBy('id')->first();
    }

    public static function allStores(): \Illuminate\Support\Collection
    {
        return static::query()->orderBy('id')->get();
    }

    public static function enabledStores(): \Illuminate\Support\Collection
    {
        return static::query()->where('enabled', true)->orderBy('id')->get();
    }

    public static function syncUrlDefault(): void
    {
        if (app()->bound('tiktok.route-store')) {
            return;
        }

        try {
            $id = static::query()->where('enabled', true)->orderBy('id')->value('id');
        } catch (\Throwable $e) {
            return;
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
                }
            }
        }

        return $s;
    }
}
