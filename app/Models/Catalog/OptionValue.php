<?php

namespace App\Models\Catalog;

class OptionValue extends BaseModel
{
    protected $primaryKey = 'option_value_id';

    protected $fillable = [
        'option_id',
        'image',
        'sort_order',
    ];

    public function getTable()
    {
        return $this->tableName('option_value');
    }
}
