<?php

namespace Extensions\venta\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VentaProductLink extends Model
{
    protected $table = 'venta_product_links';

    protected $fillable = [
        'venta_setting_id',
        'venta_product_id',
        'product_id',
        'sku',
    ];

    public function setting(): BelongsTo
    {
        return $this->belongsTo(VentaSetting::class, 'venta_setting_id');
    }
}
