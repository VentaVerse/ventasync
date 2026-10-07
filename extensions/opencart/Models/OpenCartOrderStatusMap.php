<?php

namespace Extensions\opencart\Models;

use Illuminate\Database\Eloquent\Model;

class OpenCartOrderStatusMap extends Model
{
    protected $table = 'opencart_order_status_map';

    protected $fillable = [
        'opencart_setting_id',
        'oc_status_id',
        'oc_status_name',
        'order_status_id',
    ];

    public function openCartSetting()
    {
        return $this->belongsTo(OpenCartSetting::class);
    }
}
