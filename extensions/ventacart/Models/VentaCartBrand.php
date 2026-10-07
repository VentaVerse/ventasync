<?php

namespace Extensions\ventacart\Models;

use Illuminate\Database\Eloquent\Model;

class VentaCartBrand extends Model
{
    protected $table = 'ventacart_brands';

    protected $fillable = [
        'ventacart_setting_id',
        'ventacart_brand_id',
        'name',
        'slug',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
