<?php

namespace App\Models\Catalog;

class Brand extends BaseModel
{
    protected $primaryKey = 'brand_id';
    protected $fillable = ['name','image','sort_order'];

    public function getTable()
    {
        return $this->tableName('brand');
    }
}
