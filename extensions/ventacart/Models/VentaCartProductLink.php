<?php

namespace Extensions\VentaCart\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VentaCartProductLink extends Model
{
    protected $table = 'ventacart_product_links';

    protected $fillable = [
        'store_id',
        'ventacart_product_id',
        'product_id',
        'sku',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(VentaCartStore::class, 'store_id');
    }
}
