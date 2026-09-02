<?php

namespace Extensions\shopee\Models;

use Illuminate\Database\Eloquent\Model;

class ShopeeItemCache extends Model
{
    use \Extensions\shopee\Models\Concerns\BelongsToShopeeStore;

    protected $table = 'shopee_item_cache';

    protected $fillable = ['shopee_setting_id', 'shopee_item_id', 'shopee_model_id', 'sku', 'item_name', 'image_url'];
}
