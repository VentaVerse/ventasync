<?php

namespace App\Models\Catalog;

class OrderProduct extends BaseModel
{
    protected $table = 'order_products';

    protected $primaryKey = 'order_product_id';

    protected $fillable = [
        'order_id',
        'product_id',
        'name',
        'sku',
        'quantity',
        'price',
        'foreign_price',
        'total',
        'foreign_total',
        'base_price',
        'cost',
        'supplier_id',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'order_id');
    }

    public function variations()
    {
        return $this->hasMany(OrderVariation::class, 'order_product_id', 'order_product_id');
    }
}
