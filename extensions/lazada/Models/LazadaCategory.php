<?php

namespace Extensions\lazada\Models;

use Illuminate\Database\Eloquent\Model;

class LazadaCategory extends Model
{
    protected $table = 'lazada_categories';

    protected $fillable = [
        'category_id',
        'name',
        'leaf',
        'var',
        'parent_id',
        'level',
    ];

    protected $casts = [
        'leaf' => 'boolean',
        'var' => 'boolean',
        'parent_id' => 'integer',
        'level' => 'integer',
    ];
}
