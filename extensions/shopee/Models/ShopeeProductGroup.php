<?php

namespace Extensions\Shopee\Models;

use Illuminate\Database\Eloquent\Model;

class ShopeeProductGroup extends Model
{
    use \Extensions\Shopee\Models\Concerns\BelongsToShopeeStore;

    protected $table = 'ch_shopee_product_groups';

    protected $fillable = ['store_id', 'name'];

    public function groupProducts()
    {
        return $this->hasMany(ShopeeProductGroupProduct::class, 'shopee_product_group_id');
    }
}
