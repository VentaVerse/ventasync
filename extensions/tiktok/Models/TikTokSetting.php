<?php

namespace Extensions\tiktok\Models;

use Illuminate\Database\Eloquent\Model;

class TikTokSetting extends Model
{
    protected $table = 'tiktok_settings';

    protected $fillable = [
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
        'order_tab_map' => 'array',
        'expires_at' => 'datetime',
        'refresh_expires_at' => 'datetime',
        'sandbox_expires_at' => 'datetime',
        'sandbox_refresh_expires_at' => 'datetime',
        'last_order_sync_at' => 'datetime',
        'last_stock_push_at' => 'datetime',
    ];

    /**
     * Credentials for whichever mode the shop runs in, or null when any
     * part is missing. The same four keys the controllers' creds() build.
     *
     * @return array{app_key: string, app_secret: string, token: string, shop_cipher: string}|null
     */
    public static function credentials(): ?array
    {
        $s = static::query()->first();
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
