<?php

namespace Extensions\Shopee\Models;

use Illuminate\Database\Eloquent\Model;

class ShopeeOrderProduct extends Model
{
    protected $table = 'ch_shopee_order_products';

    protected $fillable = [
        'shopee_order_id',
        'shopee_item_id',
        'shopee_model_id',
        'sku',
        'name',
        'variation',
        'quantity',
        'price',
        'image',
        'raw',
    ];

    protected $hidden = ['raw'];

    protected $casts = [
        'raw' => 'array',
        'quantity' => 'int',
        'price' => 'float',
    ];

    public function order()
    {
        return $this->belongsTo(ShopeeOrder::class, 'shopee_order_id');
    }
}
