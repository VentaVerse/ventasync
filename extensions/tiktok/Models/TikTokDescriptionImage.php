<?php

namespace Extensions\TikTok\Models;

use Illuminate\Database\Eloquent\Model;

class TikTokDescriptionImage extends Model
{
    protected $table = 'ch_tiktok_description_images';

    protected $fillable = ['store_id', 'content_hash', 'url', 'uri', 'width', 'height'];

    protected $casts = [
        'store_id' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
    ];
}
