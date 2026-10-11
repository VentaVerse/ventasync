<?php

namespace App\Models\Catalog;

class Category extends BaseModel
{
    protected $table = 'categories';

    protected $primaryKey = 'category_id';

    protected $fillable = [
        'image','parent_id','top','column','sort_order','status','date_added','date_modified'
    ];
}
