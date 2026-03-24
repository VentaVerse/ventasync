<?php

namespace Extensions\venta\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VentaOrderProduct extends Model
{
    protected $table = 'venta_order_products';

    protected $fillable = [
        'venta_order_id',
        'sku',
        'name',
        'variant_label',
        'quantity',
        'price',
        'total',
        'raw',
    ];

    protected $casts = [
        'raw'      => 'array',
        'price'    => 'decimal:2',
        'total'    => 'decimal:2',
        'quantity' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(VentaOrder::class, 'venta_order_id');
    }
}
