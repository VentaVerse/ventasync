<?php

namespace App\Models\Catalog;

class Brand extends BaseModel
{
    protected $table = 'brands';

    protected $primaryKey = 'brand_id';
    protected $fillable = ['name','image','sort_order'];
}
