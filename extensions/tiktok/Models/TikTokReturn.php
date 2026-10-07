<?php

namespace Extensions\tiktok\Models;

use Illuminate\Database\Eloquent\Model;
use Extensions\tiktok\Models\Concerns\BelongsToTikTokStore;

class TikTokReturn extends Model
{
    use BelongsToTikTokStore;

    protected $table = 'tiktok_returns';

    protected $fillable = [
        'tiktok_setting_id', 'return_id', 'order_id', 'return_type', 'return_status', 'reason',
        'refund_amount', 'currency', 'return_created_at', 'return_updated_at',
        'tracking_number', 'items', 'raw',
    ];

    protected $hidden = ['raw'];

    protected $casts = [
        'items' => 'array',
        'raw' => 'array',
        'refund_amount' => 'float',
        'return_created_at' => 'datetime',
        'return_updated_at' => 'datetime',
    ];
}
