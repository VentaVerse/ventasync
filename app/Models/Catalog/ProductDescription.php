<?php

namespace App\Models\Catalog;

class ProductDescription extends BaseModel
{
    use \App\Models\Concerns\HasCompositeKey;

    protected $table = 'product_descriptions';

    protected $primaryKey = null;
    public $incrementing = false;

    protected function keyColumns(): array
    {
        return ['product_id', 'language_id'];
    }

    protected $fillable = [
        'product_id','language_id','name','description','meta_title','meta_description','meta_keyword','tag'
    ];
}
