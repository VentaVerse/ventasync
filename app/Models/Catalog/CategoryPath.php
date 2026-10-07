<?php

namespace App\Models\Catalog;

class CategoryPath extends BaseModel
{
    protected $primaryKey = null;
    public $incrementing = false;

    protected $fillable = ['category_id','path_id','level'];

    public function getTable()
    {
        return $this->tableName('category_path');
    }
}
