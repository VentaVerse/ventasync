<?php

namespace App\Models\Catalog;

class OptionValue extends BaseModel
{
    protected $table = 'variation_values';

    protected $primaryKey = 'option_value_id';

    protected $fillable = [
        'option_id',
        'image',
        'sort_order',
    ];
}
