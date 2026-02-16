<?php

namespace App\Models;

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
        'status',
        'image',
        'raw',
    ];

    protected $casts = [
        'raw' => 'array',
        'quantity' => 'int',
    ];

    public function order()
    {
        return $this->belongsTo(LazadaOrder::class, 'lazada_order_id');
    }
}
