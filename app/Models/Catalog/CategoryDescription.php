<?php

namespace App\Models\Catalog;

class CategoryDescription extends BaseModel
{
    protected $primaryKey = null;
    public $incrementing = false;

    protected $fillable = [
        'category_id','language_id','name','description','meta_title','meta_description','meta_keyword',
        'seo_keyword','seo_h1','seo_h2','seo_h3'
    ];

    public function getTable()
    {
        return $this->tableName('category_description');
    }
}
