<?php

namespace Extensions\shopee\Models;

use Illuminate\Database\Eloquent\Model;

class ShopeeItemCache extends Model
{
    protected $table = 'shopee_item_cache';

    protected $fillable = ['shopee_item_id', 'shopee_model_id', 'sku', 'item_name', 'image_url'];
}
