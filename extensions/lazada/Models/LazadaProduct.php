<?php

namespace Extensions\lazada\Models;

use Illuminate\Database\Eloquent\Model;

class LazadaProduct extends Model
{
    // Lazada products table
    protected $table = 'lazada_products';

    protected $fillable = [
        'product_id', 'primary_category_id', 'brand_id', 'brand_name_override',
        'total_price', 'lazada_item_id', 'unlinked_at',
    ];

    protected $casts = [
        'unlinked_at' => 'datetime',
    ];

    public function attributes()
    {
        return $this->hasMany(LazadaProductAttribute::class, 'lazada_product_id');
    }

    public function groups()
    {
        return $this->belongsToMany(LazadaProductGroup::class, 'lazada_product_group_products');
    }

    public function variants()
    {
        return $this->hasMany(LazadaProductVariant::class, 'lazada_product_id');
    }
}
