<?php

namespace Extensions\VentaCart\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VentaCartOrderStatusMap extends Model
{
    protected $table = 'ch_ventacart_order_status_map';

    protected $fillable = [
        'store_id',
        'ventacart_status_id',
        'ventacart_status_name',
        'order_status_id',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(VentaCartStore::class, 'store_id');
    }
}
