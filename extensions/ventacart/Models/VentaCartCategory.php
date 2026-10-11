<?php

namespace Extensions\VentaCart\Models;

use Illuminate\Database\Eloquent\Model;

class VentaCartCategory extends Model
{
    protected $table = 'ch_ventacart_categories';

    protected $fillable = [
        'store_id',
        'ventacart_category_id',
        'name',
        'slug',
        'parent_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
