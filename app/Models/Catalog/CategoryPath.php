<?php

namespace App\Models\Catalog;

class CategoryPath extends BaseModel
{
    use \App\Models\Concerns\HasCompositeKey;

    protected $table = 'category_paths';

    protected $primaryKey = null;
    public $incrementing = false;

    protected function keyColumns(): array
    {
        return ['category_id', 'path_id'];
    }

    protected $fillable = ['category_id','path_id','level'];
}
