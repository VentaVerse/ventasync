<?php

namespace App\Models\Catalog;

class ProductDescription extends BaseModel
{
    protected $primaryKey = null;
    public $incrementing = false;

    protected $fillable = [
        'product_id','language_id','name','description','meta_title','meta_description','meta_keyword','tag'
    ];

    public function getTable()
    {
        return $this->tableName('product_description');
    }
}
