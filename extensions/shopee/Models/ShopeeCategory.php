<?php

namespace Extensions\shopee\Models;

use Illuminate\Database\Eloquent\Model;

class ShopeeCategory extends Model
{
    protected $table = 'shopee_categories';

    protected $fillable = [
        'category_id',
        'parent_id',
        'name',
        'level',
        'leaf',
    ];

    protected $casts = [
        'category_id' => 'integer',
        'parent_id' => 'integer',
        'level' => 'integer',
        'leaf' => 'boolean',
    ];
}
