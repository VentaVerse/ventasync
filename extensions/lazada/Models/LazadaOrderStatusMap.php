<?php

namespace Extensions\Lazada\Models;

use Illuminate\Database\Eloquent\Model;

class LazadaOrderStatusMap extends Model
{
    protected $table = 'ch_lazada_order_status_map';

    protected $fillable = [
        'lazada_status',
        'context',
        'order_status_id',
    ];
}
