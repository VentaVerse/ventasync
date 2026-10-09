<?php

namespace App\Models\Catalog;

class OrderOption extends BaseModel
{
    protected $table = 'order_options';

    protected $primaryKey = 'order_option_id';

    protected $fillable = [
        'order_id',
        'order_product_id',
        'product_option_id',
        'product_option_value_id',
        'name',
        'value',
        'type',
    ];

    public function orderProduct()
    {
        return $this->belongsTo(OrderProduct::class, 'order_product_id', 'order_product_id');
    }
}
