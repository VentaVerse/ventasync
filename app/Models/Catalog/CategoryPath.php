<?php

namespace App\Models\Catalog;

class CategoryPath extends BaseModel
{
    protected $table = 'category_paths';

    protected $primaryKey = null;
    public $incrementing = false;

    protected $fillable = ['category_id','path_id','level'];
}
