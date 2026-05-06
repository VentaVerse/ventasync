<?php

namespace Extensions\shopee\Models;

use Illuminate\Database\Eloquent\Model;

class ShopeeOrderStatusMap extends Model
{
    protected $table = 'shopee_order_status_map';

    protected $fillable = [
        'shopee_status',
        'context',
        'order_status_id',
    ];
}
