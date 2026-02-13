<?php

namespace App\Models\Catalog;

class Option extends BaseModel
{
    protected $primaryKey = 'option_id';

    protected $fillable = [
        'type',
        'sort_order',
    ];

    public function getTable()
    {
        return $this->tableName('option');
    }
}
