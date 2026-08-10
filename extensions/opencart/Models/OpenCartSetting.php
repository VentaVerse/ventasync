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

    /**
     * Set the API key without encrypting (for temporary/unsaved instances).
     */
    public function setRawApiKey(string $value): void
    {
        $this->attributes['api_key'] = $value;
    }

    /**
     * A store gets its three scheduled jobs the moment it exists.
     *
     * On the model rather than in the controller that happens to create most
     * of them: a store made by a seeder, by tinker, or by a second code path
     * added later needs the same rows, and relying on one call site to
     * remember is how a store ends up with an empty Automations tab and syncs
     * that never run. ensureFor() is idempotent, so this cannot duplicate a
     * row or reset a cadence the operator has edited.
     */
    protected static function booted(): void
    {
        static::created(function (self $store) {
            \Extensions\opencart\Support\OpencartScheduledJobs::ensureFor((int) $store->id);
        });
    }
}
