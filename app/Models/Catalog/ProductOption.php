<?php

namespace App\Models\Catalog;

class ProductOption extends BaseModel
{
    protected $table = 'product_variation_types';

    protected $primaryKey = 'product_option_id';

    protected $fillable = [
        'product_id',
        'option_id',
        'value',
        'required',
    ];
}
