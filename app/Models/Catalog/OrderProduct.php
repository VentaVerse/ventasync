<?php

namespace App\Models\Catalog;

class OrderProduct extends BaseModel
{
    protected $primaryKey = 'order_product_id';

    protected $fillable = [
        'order_id',
        'product_id',
        'name',
        'model',
        'quantity',
        'price',
        'total',
        'tax',
        'reward',
        'base_price',
        'cost',
        'supplier_id',
    ];

    public function getTable()
    {
        return $this->tableName('order_product');
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'order_id');
    }

    public function options()
    {
        return $this->hasMany(OrderOption::class, 'order_product_id', 'order_product_id');
    }
}
