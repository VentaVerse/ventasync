<?php

namespace Extensions\Shopee\Models;

use Illuminate\Database\Eloquent\Model;

class ShopeeOrderStatusMap extends Model
{
    protected $table = 'ch_shopee_order_status_map';

    protected $fillable = [
        'shopee_status',
        'context',
        'order_status_id',
    ];
}
