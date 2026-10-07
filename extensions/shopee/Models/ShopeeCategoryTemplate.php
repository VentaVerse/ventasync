<?php

namespace Extensions\shopee\Models;

use Illuminate\Database\Eloquent\Model;

class ShopeeCategoryTemplate extends Model
{
    protected $table = 'shopee_category_templates';

    protected $fillable = ['category_id', 'region', 'attributes', 'fetched_at'];

    protected $casts = [
        'attributes' => 'array',
        'fetched_at' => 'datetime',
    ];
}
