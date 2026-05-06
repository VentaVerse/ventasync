<?php

namespace Extensions\lazada\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LazadaOrderItem extends Model
{
    protected $table = 'lazada_order_items';

    protected $fillable = [
        'lazada_order_id',
        'order_item_id',
        'sku',
        'name',
        'quantity',
        'status',
        'raw',
    ];

    protected $hidden = ['raw'];

    protected $casts = [
        'raw' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(LazadaOrder::class, 'lazada_order_id');
    }
}
