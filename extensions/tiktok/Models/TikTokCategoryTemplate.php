<?php

namespace Extensions\TikTok\Models;

use Illuminate\Database\Eloquent\Model;

class TikTokCategoryTemplate extends Model
{
    protected $table = 'ch_tiktok_category_templates';

    protected $fillable = ['category_id', 'attributes', 'fetched_at'];

    protected $casts = [
        'attributes' => 'array',
        'fetched_at' => 'datetime',
    ];
}
