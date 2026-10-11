<?php

namespace Extensions\VentaCart\Models;

use Illuminate\Database\Eloquent\Model;

class VentaCartBrand extends Model
{
    protected $table = 'ch_ventacart_brands';

    protected $fillable = [
        'store_id',
        'ventacart_brand_id',
        'name',
        'slug',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
