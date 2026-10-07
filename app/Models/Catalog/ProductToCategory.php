<?php

namespace App\Models\Catalog;

class ProductToCategory extends BaseModel
{
    protected $primaryKey = null;
    public $incrementing = false;

    protected $fillable = ['product_id','category_id'];

    public function getTable()
    {
        return $this->tableName('product_to_category');
    }
}
