<?php

namespace App\Models\Catalog;

class ProductToCategory extends BaseModel
{
    protected $table = 'product_categories';

    protected $primaryKey = null;
    public $incrementing = false;

    protected $fillable = ['product_id','category_id'];
}
