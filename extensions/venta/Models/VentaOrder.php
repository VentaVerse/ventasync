<?php

namespace Extensions\venta\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VentaOrder extends Model
{
    protected $table = 'venta_orders';

    protected $fillable = [
        'venta_setting_id',
        'venta_order_id',
        'venta_order_number',
        'status',
        'status_id',
        'customer_name',
        'customer_email',
        'total',
        'payment_method',
        'shipping_method',
        'tracking_number',
        'shipping_address',
        'raw',
        'catalog_order_id',
        'order_created_at',
        'order_updated_at',
    ];

    protected $casts = [
        'shipping_address' => 'array',
        'raw'              => 'array',
        'total'            => 'decimal:2',
        'order_created_at' => 'datetime',
        'order_updated_at' => 'datetime',
    ];

    public function setting(): BelongsTo
    {
        return $this->belongsTo(VentaSetting::class, 'venta_setting_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(VentaOrderProduct::class, 'venta_order_id');
    }
}
