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

    /**
     * Whether a courier holds this parcel under a booking the ERP knows
     * about. The storefront's own To Handover membership is by status;
     * this is the narrower question "do we have a tracking number", which
     * is what decides whether a waybill can be printed from here.
     */
    public function isBooked(): bool
    {
        return trim((string) $this->courier_tracking_number) !== '';
    }

    /**
     * Whether the parcel left with a courier the store does not integrate
     * with, recorded by hand. Such a shipment has no waybill to print and
     * no timeline to track; what it has is a name and a number.
     */
    public function isManualShipment(): bool
    {
        return $this->courier_provider === self::MANUAL_PROVIDER;
    }

    /** The provider key a hand-recorded shipment carries. */
    public const MANUAL_PROVIDER = 'manual';

    public function setting(): BelongsTo
    {
        return $this->belongsTo(VentaSetting::class, 'venta_setting_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(VentaOrderProduct::class, 'venta_order_id');
    }
}
