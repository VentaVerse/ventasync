<?php

namespace Extensions\ventacart\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VentaCartOrderProduct extends Model
{
    protected $table = 'ventacart_order_products';

    protected $fillable = [
        'ventacart_order_id',
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
        return $this->belongsTo(VentaCartOrder::class, 'ventacart_order_id');
    }
}
