<?php

namespace App\Models\Catalog;

class CategoryDescription extends BaseModel
{
    use \App\Models\Concerns\HasCompositeKey;

    protected $table = 'category_descriptions';

    protected $primaryKey = null;
    public $incrementing = false;

    protected function keyColumns(): array
    {
        return ['category_id', 'language_id'];
    }

    protected $fillable = [
        'category_id','language_id','name','description','meta_title','meta_description','meta_keyword',
        'seo_keyword','seo_h1','seo_h2','seo_h3'
    ];
}
