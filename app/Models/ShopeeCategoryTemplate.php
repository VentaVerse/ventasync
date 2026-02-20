<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopeeCategoryTemplate extends Model
{
    protected $table = 'shopee_category_templates';

    protected $guarded = [];

    protected $casts = [
        'attributes' => 'array',
        'fetched_at' => 'datetime',
    ];
}
