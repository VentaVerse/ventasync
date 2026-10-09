<?php

namespace Extensions\Shopee\Models;

use Illuminate\Database\Eloquent\Model;

class ShopeeBrand extends Model
{
    protected $table = 'ch_shopee_brands';

    protected $fillable = [
        'category_id',
        'brand_id',
        'name',
        'status',
        'raw',
    ];

    protected $casts = [
        'category_id' => 'integer',
        'brand_id'    => 'integer',
        'status'      => 'integer',
        'raw'         => 'array',
    ];
}
