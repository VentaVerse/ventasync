<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpenCartSetting extends Model
{
    protected $table = 'opencart_settings';

    protected $fillable = [
        'store_name',
        'base_url',
        'api_key',
        'enabled',
        'last_product_sync_at',
        'last_order_sync_at',
        'last_category_sync_at',
        'last_manufacturer_sync_at',
        'last_order_page',
        'sync_log',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'last_product_sync_at' => 'datetime',
        'last_order_sync_at' => 'datetime',
        'last_category_sync_at' => 'datetime',
        'last_manufacturer_sync_at' => 'datetime',
        'sync_log' => 'array',
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
}
