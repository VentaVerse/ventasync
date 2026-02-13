<?php

namespace App\Models\Catalog;

class OptionDescription extends BaseModel
{
    public $incrementing = false;
    protected $primaryKey = null;

    protected $fillable = [
        'option_id',
        'language_id',
        'name',
    ];

    public function getTable()
    {
        return $this->tableName('option_description');
    }
}
