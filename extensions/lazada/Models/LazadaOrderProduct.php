<?php

namespace Extensions\lazada\Models;

use Illuminate\Database\Eloquent\Model;

class LazadaOrderProduct extends Model
{
    protected $table = 'lazada_order_products';

    protected $fillable = [
        'lazada_order_id',
        'order_item_id',
        'sku',
        'name',
        'variation',
        'quantity',
        'item_price',
        'paid_price',
        'status',
        'image',
        'raw',
    ];

    protected $hidden = ['raw'];

    protected $casts = [
        'raw' => 'array',
        'quantity' => 'int',
        'item_price' => 'float',
        'paid_price' => 'float',
    ];

    public function order()
    {
        return $this->belongsTo(LazadaOrder::class, 'lazada_order_id');
    }
}
