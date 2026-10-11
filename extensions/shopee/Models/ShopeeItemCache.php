<?php

namespace Extensions\Shopee\Models;

use Illuminate\Database\Eloquent\Model;

class ShopeeItemCache extends Model
{
    use \Extensions\Shopee\Models\Concerns\BelongsToShopeeStore;

    protected $table = 'ch_shopee_item_cache';

    protected $fillable = ['store_id', 'shopee_item_id', 'shopee_model_id', 'sku', 'item_name', 'image_url'];
}
