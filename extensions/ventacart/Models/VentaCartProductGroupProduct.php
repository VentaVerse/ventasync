<?php

namespace Extensions\ventacart\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VentaCartProductGroupProduct extends Model
{
    protected $table = 'ventacart_product_group_products';

    protected $fillable = [
        'ventacart_product_group_id',
        'product_id',
        'ventacart_sku',
        'sync_status',
        'last_pushed_at',
        'push_error',
    ];

    protected $casts = [
        'last_pushed_at' => 'datetime',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(VentaCartProductGroup::class, 'ventacart_product_group_id');
    }
}
