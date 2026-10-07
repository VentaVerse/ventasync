<?php

namespace App\Models\Catalog;

class Category extends BaseModel
{
    protected $primaryKey = 'category_id';

    protected $fillable = [
        'image','parent_id','top','column','sort_order','status','date_added','date_modified'
    ];

    public function getTable()
    {
        return $this->tableName('category');
    }
}
