<?php

namespace Extensions\shopee\Models;

use Illuminate\Database\Eloquent\Model;

class ShopeeSetting extends Model
{
    protected $table = 'shopee_settings';

    protected $fillable = [
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
        'api_logging',
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
        'api_logging' => 'boolean',
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
}
