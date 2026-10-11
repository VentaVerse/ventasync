<?php

namespace Extensions\Lazada\Models;

use Illuminate\Database\Eloquent\Model;

class LazadaStore extends Model
{
    use \App\Plans\CountsAsStore;

    protected $table = 'ch_lazada_stores';

    protected $fillable = [
        'store_name',
        'enabled',
        'mode',
        'region',
        'app_key',
        'app_secret',
        'redirect_uri',
        'auth_code',
        'access_token',
        'refresh_token',
        'expires_at',
        'refresh_expires_at',
        'account',
        'country',
        'sync_last_days',
        'sync_last_days_returns',
        'api_log_mode',
        'last_order_sync_at',
        'last_stock_push_at',
        'last_return_sync_at',
        'last_review_sync_at',
        'sandbox_app_key',
        'sandbox_app_secret',
        'sandbox_redirect_uri',
        'sandbox_auth_code',
        'sandbox_access_token',
        'sandbox_refresh_token',
        'sandbox_expires_at',
        'sandbox_refresh_expires_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'expires_at' => 'datetime',
        'refresh_expires_at' => 'datetime',
        'sandbox_expires_at' => 'datetime',
        'sandbox_refresh_expires_at' => 'datetime',
        'sync_last_days' => 'integer',
        'sync_last_days_returns' => 'integer',
        'last_order_sync_at' => 'datetime',
        'last_stock_push_at' => 'datetime',
        'last_return_sync_at' => 'datetime',
        'last_review_sync_at' => 'datetime',
    ];

    public function decrypted(): object
    {
        $s = (object) $this->toArray();

        foreach (['app_secret', 'auth_code', 'access_token', 'refresh_token', 'sandbox_app_secret', 'sandbox_auth_code', 'sandbox_access_token', 'sandbox_refresh_token'] as $k) {
            if (!empty($s->$k)) {
                try {
                    $s->$k = decrypt($s->$k);
                } catch (\Throwable $e) {
                }
            }
        }

        return $s;
    }

    public static function defaultStore(): ?self
    {
        if (app()->bound('lazada.route-store')) {
            return app('lazada.route-store');
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
        if (app()->bound('lazada.route-store')) {
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

    public static function activeCredentials(?object $store): array
    {
        if (!$store) {
            return [
                'mode'          => 'live',
                'region'        => null,
                'app_key'       => null,
                'app_secret'    => null,
                'access_token'  => null,
                'refresh_token' => null,
                'expires_at'    => null,
                'complete'      => false,
            ];
        }

        $isSandbox = (($store->mode ?? 'live') === 'sandbox');

        $appKey       = $isSandbox ? ($store->sandbox_app_key       ?? null) : ($store->app_key       ?? null);
        $appSecret    = $isSandbox ? ($store->sandbox_app_secret    ?? null) : ($store->app_secret    ?? null);
        $accessToken  = $isSandbox ? ($store->sandbox_access_token  ?? null) : ($store->access_token  ?? null);
        $refreshToken = $isSandbox ? ($store->sandbox_refresh_token ?? null) : ($store->refresh_token ?? null);
        $expiresAt    = $isSandbox ? ($store->sandbox_expires_at    ?? null) : ($store->expires_at    ?? null);

        $region = $store->region ?? null;

        return [
            'mode'          => $isSandbox ? 'sandbox' : 'live',
            'region'        => $region ? (string) $region : null,
            'app_key'       => $appKey       ? (string) $appKey       : null,
            'app_secret'    => $appSecret    ? (string) $appSecret    : null,
            'access_token'  => $accessToken  ? (string) $accessToken  : null,
            'refresh_token' => $refreshToken ? (string) $refreshToken : null,
            'expires_at'    => $expiresAt,
            'complete'      => !empty($appKey) && !empty($appSecret) && !empty($accessToken),
        ];
    }
}
