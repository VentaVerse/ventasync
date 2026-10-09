<?php

namespace App\Models\Catalog;

class CategoryDescription extends BaseModel
{
    protected $table = 'category_descriptions';

    protected $primaryKey = null;
    public $incrementing = false;

    protected $fillable = [
        'category_id','language_id','name','description','meta_title','meta_description','meta_keyword',
        'seo_keyword','seo_h1','seo_h2','seo_h3'
    ];
}
