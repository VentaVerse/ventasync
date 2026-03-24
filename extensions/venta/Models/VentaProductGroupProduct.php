<?php

namespace Extensions\venta\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VentaProductGroupProduct extends Model
{
    protected $table = 'venta_product_group_products';

    protected $fillable = [
        'venta_product_group_id',
        'product_id',
        'venta_sku',
        'sync_status',
        'last_pushed_at',
        'push_error',
    ];

    protected $casts = [
        'last_pushed_at' => 'datetime',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(VentaProductGroup::class, 'venta_product_group_id');
    }
}
