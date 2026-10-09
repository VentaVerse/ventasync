<?php

namespace Extensions\OpenCart\Models;

use Illuminate\Database\Eloquent\Model;

class OpenCartProductLink extends Model
{
    protected $table = 'opencart_product_links';

    protected $fillable = [
        'store_id',
        'oc_product_id',
        'product_id',
        'sku',
    ];

    public function store()
    {
        return $this->belongsTo(OpenCartStore::class, 'store_id');
    }
}
