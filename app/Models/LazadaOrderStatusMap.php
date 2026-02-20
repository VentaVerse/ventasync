<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LazadaOrderStatusMap extends Model
{
    protected $table = 'lazada_order_status_map';

    protected $fillable = [
        'lazada_status',
        'order_status_id',
    ];
}
