<?php

namespace App\Models\Catalog;

class Option extends BaseModel
{
    protected $table = 'variation_types';

    protected $primaryKey = 'option_id';

    protected $fillable = [
        'type',
        'sort_order',
    ];
}
