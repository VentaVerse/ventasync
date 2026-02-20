<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopeeOrderStatusMap extends Model
{
    protected $table = 'shopee_order_status_map';

    protected $fillable = [
        'shopee_status',
        'order_status_id',
    ];
}
