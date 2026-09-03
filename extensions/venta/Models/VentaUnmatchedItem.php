<?php

namespace Extensions\venta\Models;

use Illuminate\Database\Eloquent\Model;

class VentaUnmatchedItem extends Model
{
    protected $table = 'venta_unmatched_items';

    protected $fillable = [
        'venta_setting_id', 'venta_product_id', 'sku', 'name', 'price',
        'quantity', 'image_url', 'variant_count', 'status', 'linked_product_id',
    ];
}
