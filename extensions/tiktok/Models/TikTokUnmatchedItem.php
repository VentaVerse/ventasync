<?php

namespace Extensions\tiktok\Models;

use Illuminate\Database\Eloquent\Model;

class TikTokUnmatchedItem extends Model
{
    protected $table = 'tiktok_unmatched_items';

    protected $fillable = [
        'tiktok_product_id', 'item_name', 'sku', 'image_url', 'status', 'linked_product_id',
    ];
}
