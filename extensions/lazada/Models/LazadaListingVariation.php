<?php

namespace Extensions\Lazada\Models;

use Illuminate\Database\Eloquent\Model;

class LazadaListingVariation extends Model
{
    protected $table = 'ch_lazada_listing_variations';

    protected $fillable = [
        'lazada_listing_id', 'seller_sku', 'sku_id', 'shop_sku', 'product_variation_value_id',
        'price', 'quantity',
    ];
}
