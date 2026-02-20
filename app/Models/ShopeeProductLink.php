<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopeeProductLink extends Model
{
    protected $table = 'shopee_product_links';

    protected $fillable = [
        'product_id',
        'shopee_item_id',
        'shopee_model_id',
        'sku',
    ];
}
