<?php

namespace Extensions\lazada\Models;

use Illuminate\Database\Eloquent\Model;

class LazadaProductGroup extends Model
{
    protected $table = 'lazada_product_groups';

    protected $fillable = [
        'name',
        'lazada_category_id', 'brand_id', 'brand_name_override',
        'markup_fixed', 'markup_percent',
    ];

    protected $casts = [
    ];

    public function groupAttributes()
    {
        return $this->hasMany(LazadaProductGroupAttribute::class, 'lazada_product_group_id');
    }

    public function products()
    {
        return $this->belongsToMany(LazadaProduct::class, 'lazada_product_group_products', 'lazada_product_group_id', 'lazada_product_id');
    }

    public function groupProducts()
    {
        return $this->hasMany(LazadaProductGroupProduct::class, 'lazada_product_group_id');
    }
}
