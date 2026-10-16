<?php

namespace App\Models\Catalog;

class ProductVariationValue extends BaseModel
{
    protected $table = 'product_variation_values';

    protected $primaryKey = 'product_variation_value_id';

    protected $fillable = [
        'product_variation_type_id',
        'product_id',
        'variation_type_id',
        'variation_value_id',
        'sku',
        'quantity',
        'subtract_stock',
        'price',        'price_prefix',
        'points',
        'points_prefix',
        'weight',
        'weight_prefix',
    ];
}
