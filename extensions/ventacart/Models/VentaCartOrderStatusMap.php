<?php

namespace Extensions\ventacart\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VentaCartOrderStatusMap extends Model
{
    protected $table = 'ventacart_order_status_map';

    protected $fillable = [
        'ventacart_setting_id',
        'ventacart_status_id',
        'ventacart_status_name',
        'order_status_id',
    ];

    public function setting(): BelongsTo
    {
        return $this->belongsTo(VentaCartSetting::class, 'ventacart_setting_id');
    }
}
