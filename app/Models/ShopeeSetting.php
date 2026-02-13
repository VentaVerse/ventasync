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
        'redirect_uri',
        'region',
    ];
}
