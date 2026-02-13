<?php

namespace App\Models\Catalog;

class Manufacturer extends BaseModel
{
    protected $primaryKey = 'manufacturer_id';
    protected $fillable = ['name','image','sort_order'];

    public function getTable()
    {
        return $this->tableName('manufacturer');
    }
}
