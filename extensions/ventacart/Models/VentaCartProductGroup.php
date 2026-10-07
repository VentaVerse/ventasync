<?php

namespace Extensions\ventacart\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VentaCartProductGroup extends Model
{
    protected $table = 'ventacart_product_groups';

    protected $fillable = [
        'watermark_template_id',
        'ventacart_setting_id',
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

    public function setting(): BelongsTo
    {
        return $this->belongsTo(VentaCartSetting::class, 'ventacart_setting_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(VentaCartProductGroupProduct::class, 'ventacart_product_group_id');
    }
}
