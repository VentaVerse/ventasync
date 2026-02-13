<?php

namespace App\Models\Catalog;

class ProductOptionValue extends BaseModel
{
    protected $primaryKey = 'product_option_value_id';

    protected $fillable = [
        'product_option_id',
        'product_id',
        'option_id',
        'option_value_id',
        'sku',
        'quantity',
        'subtract',
        'price',        'price_prefix',
        'points',
        'points_prefix',
        'weight',
        'weight_prefix',
    ];

    public function getTable()
    {
        return $this->tableName('product_option_value');
    }
}
