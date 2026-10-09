<?php

namespace Extensions\OpenCart\Models;

use Illuminate\Database\Eloquent\Model;

class OpenCartOrderStatusMap extends Model
{
    protected $table = 'ch_opencart_order_status_map';

    protected $fillable = [
        'store_id',
        'oc_status_id',
        'oc_status_name',
        'order_status_id',
    ];

    public function store()
    {
        return $this->belongsTo(OpenCartStore::class, 'store_id');
    }
}
