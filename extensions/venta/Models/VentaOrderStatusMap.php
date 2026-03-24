<?php

namespace Extensions\venta\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VentaOrderStatusMap extends Model
{
    protected $table = 'venta_order_status_map';

    protected $fillable = [
        'venta_setting_id',
        'venta_status_id',
        'venta_status_name',
        'order_status_id',
    ];

    public function setting(): BelongsTo
    {
        return $this->belongsTo(VentaSetting::class, 'venta_setting_id');
    }
}
