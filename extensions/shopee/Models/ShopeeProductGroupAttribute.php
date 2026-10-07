<?php

namespace Extensions\shopee\Models;

use Illuminate\Database\Eloquent\Model;

class ShopeeProductGroupAttribute extends Model
{
    protected $table = 'shopee_product_group_attributes';

    protected $fillable = ['shopee_product_group_id', 'attribute_key', 'value'];
}
