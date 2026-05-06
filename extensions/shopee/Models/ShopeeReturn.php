<?php

namespace Extensions\shopee\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopeeReturn extends Model
{
    protected $table = 'shopee_returns';

    protected $fillable = [
        'region',
        'return_sn',
        'order_sn',
        'shopee_order_id',
        'status',
        'reason',
        'reason_text',
        'refund_amount',
        'currency',
        'items',
        'negotiation',
        'raw',
        'return_created_at',
        'return_updated_at',
    ];

    protected $hidden = ['raw'];

    protected $casts = [
        'items' => 'array',
        'negotiation' => 'array',
        'raw' => 'array',
        'refund_amount' => 'decimal:2',
        'return_created_at' => 'datetime',
        'return_updated_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(ShopeeOrder::class, 'shopee_order_id');
    }
}
