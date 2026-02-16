<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LazadaCategoryTemplate extends Model
{
    protected $table = 'lazada_category_templates';

    protected $guarded = [];

    protected $casts = [
        'template_body' => 'array',
        'fetched_at' => 'datetime',
    ];
}
