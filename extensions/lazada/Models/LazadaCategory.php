<?php

namespace Extensions\Lazada\Models;

use Illuminate\Database\Eloquent\Model;

class LazadaCategory extends Model
{
    protected $table = 'ch_lazada_categories';

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
