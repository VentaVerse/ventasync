<?php

namespace Extensions\venta\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VentaSetting extends Model
{
    protected $table = 'venta_settings';

    protected $fillable = [
        'store_name',
        'base_url',
        'api_token',
        'enabled',
        'api_logging',
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
}
