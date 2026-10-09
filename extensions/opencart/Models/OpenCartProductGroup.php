<?php

namespace Extensions\OpenCart\Models;

use Illuminate\Database\Eloquent\Model;

class OpenCartProductGroup extends Model
{
    protected $table = 'ch_opencart_product_groups';

    protected $fillable = ['store_id', 'name',];

    protected $casts = [
    ];

    public function store()
    {
        return $this->belongsTo(OpenCartStore::class, 'store_id');
    }

    public function groupProducts()
    {
        return $this->hasMany(OpenCartProductGroupProduct::class, 'opencart_product_group_id');
    }
}
