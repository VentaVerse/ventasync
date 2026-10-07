<?php

namespace Extensions\ventacart\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VentaCartOrder extends Model
{
    protected $table = 'ventacart_orders';

    protected $fillable = [
        'ventacart_setting_id',
        'ventacart_order_id',
        'ventacart_order_number',
        'status',
        'status_id',
        'customer_name',
        'customer_email',
        'customer_group',
        'total',
        'payment_method',
        'shipping_method',
        'tracking_number',
        'courier_provider',
        'courier_name',
        'courier_tracking_number',
        'courier_status',
        'courier_booked_at',
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
        'courier_booked_at' => 'datetime',
    ];

    public function isBooked(): bool
    {
        return trim((string) $this->courier_tracking_number) !== '';
    }

    public function isManualShipment(): bool
    {
        return $this->courier_provider === self::MANUAL_PROVIDER;
    }

    public const MANUAL_PROVIDER = 'manual';

    public function setting(): BelongsTo
    {
        return $this->belongsTo(VentaCartSetting::class, 'ventacart_setting_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(VentaCartOrderProduct::class, 'ventacart_order_id');
    }
}
