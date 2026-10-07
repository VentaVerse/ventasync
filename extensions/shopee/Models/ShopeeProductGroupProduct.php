<?php

namespace Extensions\shopee\Models;

use Illuminate\Database\Eloquent\Model;

class ShopeeProductGroupProduct extends Model
{
    protected $table = 'shopee_product_group_products';

    public $timestamps = false;

    protected $fillable = [
        'shopee_product_group_id', 'product_id', 'shopee_item_id',
        'sync_status', 'last_pushed_at', 'push_error',
    ];

    protected $casts = [
        'last_pushed_at' => 'datetime',
    ];

    public function group()
    {
        return $this->belongsTo(ShopeeProductGroup::class, 'shopee_product_group_id');
    }
}
