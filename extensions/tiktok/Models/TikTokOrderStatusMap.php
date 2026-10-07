<?php

namespace Extensions\tiktok\Models;

use Illuminate\Database\Eloquent\Model;

class TikTokOrderStatusMap extends Model
{
    protected $table = 'tiktok_order_status_map';

    protected $fillable = [
        'tiktok_status',
        'context',
        'order_status_id',
    ];

    protected $casts = [
        'order_status_id' => 'integer',
    ];
}
