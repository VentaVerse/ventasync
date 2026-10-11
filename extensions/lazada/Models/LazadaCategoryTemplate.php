<?php

namespace Extensions\Lazada\Models;

use Illuminate\Database\Eloquent\Model;

class LazadaCategoryTemplate extends Model
{
    protected $table = 'ch_lazada_category_templates';

    protected $fillable = ['region', 'primary_category_id', 'template_body', 'fetched_at'];

    protected $casts = [
        'template_body' => 'array',
        'fetched_at' => 'datetime',
    ];
}
