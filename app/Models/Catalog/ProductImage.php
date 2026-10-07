<?php

namespace App\Models\Catalog;

class ProductImage extends BaseModel
{
    protected $primaryKey = 'product_image_id';

    protected $fillable = [
        'product_id',
        'image',
        'sort_order',
    ];

    public function getTable()
    {
        return $this->tableName('product_image');
    }
}
