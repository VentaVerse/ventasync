<?php

namespace Extensions\TikTok\Models;

use Illuminate\Database\Eloquent\Model;
use Extensions\TikTok\Models\Concerns\BelongsToTikTokStore;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TikTokOrder extends Model
{
    use BelongsToTikTokStore;

    protected $table = 'ch_tiktok_orders';

    protected $fillable = [
        'store_id', 'region',
        'order_id',
        'status',
        'order_created_at',
        'order_updated_at',
        'raw',
        'fees',
        'customer_name',
        'payout_status',
        'paid_at',
        'sales_order_id',
    ];

    protected $hidden = ['raw'];

    protected $casts = [
        'raw' => 'array',
        'fees' => 'array',
        'order_created_at' => 'datetime',
        'order_updated_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(TikTokOrderProduct::class, 'tiktok_order_id');
    }
}
