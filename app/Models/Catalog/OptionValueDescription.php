<?php

namespace App\Models\Catalog;

class OptionValueDescription extends BaseModel
{
    public $incrementing = false;
    protected $primaryKey = null;

    protected $fillable = [
        'option_value_id',
        'language_id',
        'option_id',
        'name',
    ];

    public function getTable()
    {
        return $this->tableName('option_value_description');
    }
}
