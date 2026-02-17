<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LazadaProduct extends Model
{
    // Lazada products table
    protected $table = 'lazada_products';

    protected $guarded = [];

    public function attributes()
    {
        return $this->hasMany(LazadaProductAttribute::class, 'lazada_product_id');
    }

    public function variants()
    {
        return $this->hasMany(LazadaProductVariant::class, 'lazada_product_id');
    }
}
