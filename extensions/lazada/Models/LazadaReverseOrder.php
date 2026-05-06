<?php

namespace Extensions\lazada\Models;

use Illuminate\Database\Eloquent\Model;

class LazadaReverseOrder extends Model
{
    protected $table = 'lazada_reverse_orders';

    protected $fillable = [
        'region',
        'reverse_order_id',
        'trade_order_id',
        'reverse_status',
        'reverse_type',
        'reason',
        'refund_amount',
        'currency',
        'items',
        'raw',
    ];

    protected $hidden = ['raw'];

    protected $casts = [
        'items' => 'array',
        'raw' => 'array',
        'refund_amount' => 'decimal:2',
    ];
}
