<?php

namespace Extensions\tiktok\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TikTokOrder extends Model
{
    protected $table = 'tiktok_orders';

    protected $fillable = [
        'region',
        'order_id',
        'status',
        'order_created_at',
        'order_updated_at',
        'raw',
        'fees',
        'buyer_name',
        'payout_status',
        'paid_at',
        'catalog_order_id',
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
