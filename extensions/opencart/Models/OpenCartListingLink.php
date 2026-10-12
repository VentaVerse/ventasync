<?php

namespace Extensions\OpenCart\Models;

use Illuminate\Database\Eloquent\Model;

class OpenCartListingLink extends Model
{
    protected $table = 'ch_opencart_listing_links';

    protected $fillable = [
        'store_id',
        'opencart_product_id',
        'product_id',
        'sku',
    ];

    public function store()
    {
        return $this->belongsTo(OpenCartStore::class, 'store_id');
    }
}
