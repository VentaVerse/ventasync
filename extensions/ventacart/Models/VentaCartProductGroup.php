<?php

namespace Extensions\VentaCart\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VentaCartProductGroup extends Model
{
    protected $table = 'ch_ventacart_product_groups';

    protected $fillable = [
        'watermark_template_id',
        'store_id',
        'name',
        'ventacart_category_id',
        'ventacart_brand_id',
        'markup_percent',
        'markup_fixed',
    ];

    protected $casts = [
        'markup_percent'       => 'decimal:2',
        'markup_fixed'         => 'decimal:2',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(VentaCartStore::class, 'store_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(VentaCartProductGroupProduct::class, 'ventacart_product_group_id');
    }
}
