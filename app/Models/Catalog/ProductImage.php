<?php

namespace App\Models\Catalog;

class ProductImage extends BaseModel
{
    protected $table = 'product_images';

    protected $primaryKey = 'product_image_id';

    protected $fillable = [
        'product_id',
        'image',
        'sort_order',
    ];
}
