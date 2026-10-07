<?php

namespace App\Models\Catalog;

class ProductOption extends BaseModel
{
    protected $primaryKey = 'product_option_id';

    protected $fillable = [
        'product_id',
        'option_id',
        'value',
        'required',
    ];

    public function getTable()
    {
        return $this->tableName('product_option');
    }
}
