<?php

namespace Extensions\tiktok\Models;

use Illuminate\Database\Eloquent\Model;

/** A TikTok category's attribute sheet, cached as TikTok returned it. */
class TikTokCategoryTemplate extends Model
{
    protected $table = 'tiktok_category_templates';

    protected $fillable = ['category_id', 'attributes', 'fetched_at'];

    protected $casts = [
        'attributes' => 'array',
        'fetched_at' => 'datetime',
    ];
}
