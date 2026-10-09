<?php

namespace App\Models\Catalog;

class VariationValue extends BaseModel
{
    protected $table = 'variation_values';

    protected $primaryKey = 'variation_value_id';

    protected $fillable = [
        'variation_type_id',
        'image',
        'sort_order',
    ];
}
