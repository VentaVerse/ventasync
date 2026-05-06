<?php

namespace Extensions\shopee\Models;

use Illuminate\Database\Eloquent\Model;

class ShopeeProductLink extends Model
{
    protected $table = 'shopee_product_links';

    protected $fillable = [
        'product_id',
        'shopee_item_id',
        'shopee_model_id',
        'sku',
        'last_synced_at',
        'last_sync_action',
        'last_sync_ok',
        'last_sync_error_code',
        'last_sync_error_message',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
        'last_sync_ok' => 'boolean',
    ];
}
