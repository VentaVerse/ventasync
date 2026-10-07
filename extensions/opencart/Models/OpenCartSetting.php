<?php

namespace Extensions\opencart\Models;

use Illuminate\Database\Eloquent\Model;

class OpenCartSetting extends Model
{
    protected $table = 'opencart_settings';

    protected $fillable = [
        'store_name',
        'brand_color',
        'base_url',
        'api_key',
        'enabled',
        'last_product_sync_at',
        'last_order_sync_at',
        'sync_orders_from',
        'sync_last_days',
        'last_category_sync_at',
        'last_manufacturer_sync_at',
        'last_order_page',
        'sync_log',
        'review_auto_approve',
        'last_review_push_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'last_product_sync_at' => 'datetime',
        'last_order_sync_at' => 'datetime',
        'sync_orders_from' => 'date',
        'sync_last_days' => 'integer',
        'last_category_sync_at' => 'datetime',
        'last_manufacturer_sync_at' => 'datetime',
        'sync_log' => 'array',
        'review_auto_approve' => 'boolean',
        'last_review_push_at' => 'datetime',
    ];

    public function setApiKeyAttribute($value)
    {
        $this->attributes['api_key'] = encrypt($value);
    }

    public function getApiKeyAttribute($value)
    {
        try {
            return decrypt($value);
        } catch (\Throwable $e) {
            return $value;
        }
    }

    public function setRawApiKey(string $value): void
    {
        $this->attributes['api_key'] = $value;
    }

    protected static function booted(): void
    {
        static::created(function (self $store) {
            \Extensions\opencart\Support\OpencartScheduledJobs::ensureFor((int) $store->id);
        });
    }
}
