<?php

namespace Extensions\shopee\Models;

use Illuminate\Database\Eloquent\Model;

class ShopeeBrand extends Model
{
    protected $table = 'shopee_brands';

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
