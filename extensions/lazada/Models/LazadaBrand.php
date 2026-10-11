<?php

namespace Extensions\Lazada\Models;

use Illuminate\Database\Eloquent\Model;

class LazadaBrand extends Model
{
    protected $table = 'ch_lazada_brands';

    protected $fillable = [
        'region',
        'brand_id',
        'name',
        'raw',
    ];

    protected $casts = [
        'raw' => 'array',
    ];
}
