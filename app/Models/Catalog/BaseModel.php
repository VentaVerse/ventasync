<?php

namespace App\Models\Catalog;

use Illuminate\Database\Eloquent\Model;

abstract class BaseModel extends Model
{
    public $timestamps = false;

    protected function tableName(string $suffix): string
    {
        return config('catalog.prefix') . $suffix;
    }
}
