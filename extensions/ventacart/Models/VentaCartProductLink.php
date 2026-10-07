<?php

namespace Extensions\ventacart\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VentaCartProductLink extends Model
{
    protected $table = 'ventacart_product_links';

    protected $fillable = [
        'ventacart_setting_id',
        'ventacart_product_id',
        'product_id',
        'sku',
    ];

    public function setting(): BelongsTo
    {
        return $this->belongsTo(VentaCartSetting::class, 'ventacart_setting_id');
    }
}
