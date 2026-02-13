<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LazadaSetting extends Model
{
    protected $table = 'lazada_settings';

    protected $fillable = [
        'region',
        'app_key',
        'app_secret',
        'redirect_uri',
        'access_token',
        'refresh_token',
        'expires_at',
        'refresh_expires_at',
        'account',
        'country',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'refresh_expires_at' => 'datetime',
    ];
}
