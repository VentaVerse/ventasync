<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductVendor extends Model
{
    protected $fillable = [
        'product_id',
        'vendor_id',
        'product_option_value_id',
        'vendor_sku',
        'min_qty',
        'currency_code',
        'unit_price',
        'notes',
    ];

    protected $casts = [
        'unit_price' => 'decimal:4',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }
}
