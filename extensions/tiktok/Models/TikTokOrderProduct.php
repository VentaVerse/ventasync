<?php

namespace Extensions\tiktok\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TikTokOrderProduct extends Model
{
    protected $table = 'tiktok_order_products';

    protected $fillable = [
        'tiktok_order_id',
        'order_line_item_id',
        'sku',
        'name',
        'variation',
        'quantity',
        'item_price',
        'sale_price',
        'status',
        'image',
        'raw',
    ];

    protected $hidden = ['raw'];

    protected $casts = [
        'raw' => 'array',
        'quantity' => 'int',
        'item_price' => 'float',
        'sale_price' => 'float',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(TikTokOrder::class, 'tiktok_order_id');
    }
}
