<?php

namespace Extensions\ventacart\Models;

use Illuminate\Database\Eloquent\Model;

class VentaCartCategory extends Model
{
    protected $table = 'ventacart_categories';

    protected $fillable = [
        'ventacart_setting_id',
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
