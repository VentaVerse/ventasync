<?php

namespace Extensions\shopee\Models;

use Illuminate\Database\Eloquent\Model;

class ShopeeUnmatchedItem extends Model
{
    protected $table = 'shopee_unmatched_items';

    protected $fillable = [
        'shopee_item_id',
        'shopee_model_id',
        'item_name',
        'sku',
        'image_url',
        'raw_data',
        'status',
        'linked_product_id',
    ];

    protected $casts = [
        'raw_data' => 'array',
    ];
}
