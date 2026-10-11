<?php

namespace App\Models\Catalog;

class OrderVariation extends BaseModel
{
    protected $table = 'order_variations';

    protected $primaryKey = 'order_variation_id';

    protected $fillable = [
        'order_id',
        'order_product_id',
        'product_variation_type_id',
        'product_variation_value_id',
        'name',
        'value',
        'type',
    ];

    public function orderProduct()
    {
        return $this->belongsTo(OrderProduct::class, 'order_product_id', 'order_product_id');
    }
}
