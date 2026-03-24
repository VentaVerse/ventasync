<?php

namespace Extensions\venta\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VentaProductGroup extends Model
{
    protected $table = 'venta_product_groups';

    protected $fillable = [
        'venta_setting_id',
        'name',
        'venta_category_id',
        'venta_brand_id',
        'catalog_category_ids',
        'manufacturer_ids',
        'markup_percent',
        'markup_fixed',
    ];

    protected $casts = [
        'catalog_category_ids' => 'array',
        'manufacturer_ids'     => 'array',
        'markup_percent'       => 'decimal:2',
        'markup_fixed'         => 'decimal:2',
    ];

    public function setting(): BelongsTo
    {
        return $this->belongsTo(VentaSetting::class, 'venta_setting_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(VentaProductGroupProduct::class, 'venta_product_group_id');
    }
}
