<?php

namespace Extensions\tiktok\Models;

use Illuminate\Database\Eloquent\Model;

class TikTokDescriptionImage extends Model
{
    protected $table = 'tiktok_description_images';

    protected $fillable = ['tiktok_setting_id', 'content_hash', 'url', 'uri', 'width', 'height'];

    protected $casts = [
        'tiktok_setting_id' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
    ];
}
