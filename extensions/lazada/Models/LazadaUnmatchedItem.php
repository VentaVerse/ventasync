<?php

namespace Extensions\lazada\Models;

use Illuminate\Database\Eloquent\Model;

class LazadaUnmatchedItem extends Model
{
    protected $table = 'lazada_unmatched_items';

    protected $fillable = [
        'lazada_item_id',
        'lazada_sku_id',
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
