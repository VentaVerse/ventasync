<?php

namespace Extensions\opencart\Models;

use Illuminate\Database\Eloquent\Model;

class OpenCartUnmatchedItem extends Model
{
    protected $table = 'opencart_unmatched_items';

    protected $fillable = [
        'opencart_setting_id', 'oc_product_id', 'sku', 'name', 'price',
        'quantity', 'image_url', 'variant_count', 'status', 'linked_product_id',
    ];
}
