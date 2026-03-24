<?php

namespace Extensions\venta\Models;

use Illuminate\Database\Eloquent\Model;

class VentaCategory extends Model
{
    protected $table = 'venta_categories';

    protected $fillable = [
        'venta_setting_id',
        'venta_category_id',
        'name',
        'slug',
        'parent_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
