<?php

namespace App\Models\Catalog;

class ProductToCategory extends BaseModel
{
    use \App\Models\Concerns\HasCompositeKey;

    protected $table = 'product_categories';

    protected $primaryKey = null;
    public $incrementing = false;

    protected function keyColumns(): array
    {
        return ['product_id', 'category_id'];
    }

    protected $fillable = ['product_id','category_id'];
}
