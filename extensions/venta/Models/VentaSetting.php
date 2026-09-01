<?php

namespace Extensions\venta\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VentaSetting extends Model
{
    protected $table = 'venta_settings';

    protected $fillable = [
        'store_name',
        'brand_color',
        'base_url',
        'api_token',
        'enabled',
        'api_logging',
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
        'api_logging'          => 'boolean',
        'sync_last_days'       => 'integer',
        'sync_orders_from'     => 'date',
        'last_order_sync_at'   => 'datetime',
        'last_product_sync_at' => 'datetime',
        'last_category_sync_at'=> 'datetime',
        'last_stock_push_at'   => 'datetime',
        'last_review_push_at'  => 'datetime',
    ];

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
        return $this->hasMany(VentaProductGroup::class, 'venta_setting_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(VentaOrder::class, 'venta_setting_id');
    }

    public function statusMaps(): HasMany
    {
        return $this->hasMany(VentaOrderStatusMap::class, 'venta_setting_id');
    }

    public function productLinks(): HasMany
    {
        return $this->hasMany(VentaProductLink::class, 'venta_setting_id');
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(VentaSyncLog::class, 'venta_setting_id');
    }

    /**
     * A store gets its three scheduled jobs the moment it exists.
     *
     * On the model rather than in the controller that happens to create most
     * of them: a store made by a seeder, by tinker, or by a second code path
     * added later needs the same rows, and relying on one call site to
     * remember is how a store ends up with an empty Automations tab and syncs
     * that never run, with nothing on screen explaining why.
     *
     * ensureFor() is idempotent, so this cannot duplicate rows or reset a
     * cadence the operator has edited.
     */
    protected static function booted(): void
    {
        static::created(function (self $store) {
            \Extensions\venta\Support\VentaScheduledJobs::ensureFor((int) $store->id);
        });
    }
}
