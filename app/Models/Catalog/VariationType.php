<?php

namespace App\Models\Catalog;

class VariationType extends BaseModel
{
    protected $table = 'variation_types';

    protected $primaryKey = 'variation_type_id';

    protected $fillable = [
        'type',
        'sort_order',
    ];
}
