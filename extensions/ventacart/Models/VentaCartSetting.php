<?php

namespace Extensions\ventacart\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VentaCartSetting extends Model
{
    use \App\Plans\CountsAsStore;

    protected $table = 'ventacart_settings';

    protected $fillable = [
        'store_name',
        'brand_color',
        'base_url',
        'api_token',
        'enabled',
        'api_log_mode',
        'sync_log_level',
        'warehouse_id',
        'sync_last_days',
        'sync_orders_from',
        'last_order_sync_at',
        'last_product_sync_at',
        'last_category_sync_at',
        'last_stock_push_at',
        'last_review_push_at',
    ];

    protected $casts = [
        'enabled'              => 'boolean',
        'sync_last_days'       => 'integer',
        'sync_orders_from'     => 'date',
        'last_order_sync_at'   => 'datetime',
        'last_product_sync_at' => 'datetime',
        'last_category_sync_at'=> 'datetime',
        'last_stock_push_at'   => 'datetime',
        'last_review_push_at'  => 'datetime',
        'connected_at'         => 'datetime',
    ];

    public function isConnected(): bool
    {
        return trim((string) ($this->base_url ?? '')) !== ''
            && trim((string) ($this->api_token ?? '')) !== '';
    }

    public function setApiTokenAttribute($value)
    {
        $this->attributes['api_token'] = encrypt($value);
    }

    public function getApiTokenAttribute($value)
    {
        try {
            return decrypt($value);
        } catch (\Throwable $e) {
            return $value;
        }
    }

    public function setRawApiToken(string $value): void
    {
        $this->attributes['api_token'] = $value;
    }

    public function productGroups(): HasMany
    {
        return $this->hasMany(VentaCartProductGroup::class, 'ventacart_setting_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(VentaCartOrder::class, 'ventacart_setting_id');
    }

    public function statusMaps(): HasMany
    {
        return $this->hasMany(VentaCartOrderStatusMap::class, 'ventacart_setting_id');
    }

    public function productLinks(): HasMany
    {
        return $this->hasMany(VentaCartProductLink::class, 'ventacart_setting_id');
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(VentaCartSyncLog::class, 'ventacart_setting_id');
    }

    protected static function booted(): void
    {
        static::created(function (self $store) {
            \Extensions\ventacart\Support\VentaCartScheduledJobs::ensureFor((int) $store->id);
        });
    }
}
