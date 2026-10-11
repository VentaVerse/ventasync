<?php

namespace Extensions\VentaCart\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VentaCartListingLink extends Model
{
    protected $table = 'ch_ventacart_listing_links';

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
