<?php

namespace Extensions\lazada\Models;

use Illuminate\Database\Eloquent\Model;

class LazadaOrderStatusMap extends Model
{
    protected $table = 'lazada_order_status_map';

    protected $fillable = [
        'lazada_status',
        'context',
        'order_status_id',
    ];
}
