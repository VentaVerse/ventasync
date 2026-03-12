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
        'order_tab_map',
        'api_logging',
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
        'api_logging' => 'boolean',
        'order_tab_map' => 'array',
        'expires_at' => 'datetime',
        'refresh_expires_at' => 'datetime',
        'sandbox_expires_at' => 'datetime',
        'sandbox_refresh_expires_at' => 'datetime',
        'last_order_sync_at' => 'datetime',
        'last_stock_push_at' => 'datetime',
    ];

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
