<?php

namespace Extensions\lazada\Models;

use Illuminate\Database\Eloquent\Model;

class LazadaProductVariant extends Model
{
    protected $table = 'lazada_product_variants';

    protected $fillable = [
        'lazada_product_id', 'seller_sku', 'sku_id', 'shop_sku', 'product_option_value_id',
        'price', 'quantity',
    ];
}
