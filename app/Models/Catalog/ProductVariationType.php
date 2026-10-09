<?php

namespace App\Models\Catalog;

class ProductVariationType extends BaseModel
{
    protected $table = 'product_variation_types';

    protected $primaryKey = 'product_variation_type_id';

    protected $fillable = [
        'product_id',
        'variation_type_id',
        'value',
        'required',
    ];
}
