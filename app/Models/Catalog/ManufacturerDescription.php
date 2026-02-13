<?php

namespace App\Models\Catalog;

class ManufacturerDescription extends BaseModel
{
    protected $primaryKey = null;
    public $incrementing = false;

    protected $fillable = [
        'manufacturer_id',
        'language_id',
        'description',
    ];

    public function getTable()
    {
        return $this->tableName('manufacturer_description');
    }
}
