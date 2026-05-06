<?php

namespace Extensions\shopee\Models;

use Illuminate\Database\Eloquent\Model;

class ShopeeProductGroup extends Model
{
    protected $table = 'shopee_product_groups';

    protected $fillable = [
        'name', 'catalog_category_ids', 'manufacturer_ids',
        'shopee_category_id', 'logistic_ids',
        'markup_fixed', 'markup_percent',
    ];

    protected $casts = [
        'catalog_category_ids' => 'array',
        'manufacturer_ids' => 'array',
        'logistic_ids' => 'array',
    ];

    public function groupAttributes()
    {
        return $this->hasMany(ShopeeProductGroupAttribute::class, 'shopee_product_group_id');
    }

    public function groupProducts()
    {
        return $this->hasMany(ShopeeProductGroupProduct::class, 'shopee_product_group_id');
    }
}
