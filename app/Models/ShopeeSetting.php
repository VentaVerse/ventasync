<?php

namespace App\Models;

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
        'api_logging',
    ];

    protected $casts = [
        'api_logging' => 'boolean',
        'expires_at' => 'datetime',
        'refresh_expires_at' => 'datetime',
    ];
}
