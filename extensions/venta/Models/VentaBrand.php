<?php

namespace Extensions\venta\Models;

use Illuminate\Database\Eloquent\Model;

class VentaBrand extends Model
{
    protected $table = 'venta_brands';

    protected $fillable = [
        'venta_setting_id',
        'venta_brand_id',
        'name',
        'slug',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
