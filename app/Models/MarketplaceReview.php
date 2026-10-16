<?php

namespace App\Models;

use App\Models\Catalog\Product;
use Illuminate\Database\Eloquent\Model;

class MarketplaceReview extends Model
{
    protected $table = 'ch_marketplace_reviews';

    protected $fillable = [
        'channel',
        'channel_review_id',
        'product_id',
        'channel_item_id',
        'channel_order_id',
        'author',
        'rating',
        'comment',
        'images',
        'videos',
        'reply',
        'replied_at',
        'opencart_sync_status',
        'opencart_store_id',
        'opencart_review_id',
        'opencart_pushed_at',
        'opencart_push_error',
        'ventacart_sync_status',
        'ventacart_store_id',
        'ventacart_review_id',
        'ventacart_pushed_at',
        'ventacart_push_error',
        'woocommerce_sync_status',
        'woocommerce_store_id',
        'woocommerce_review_id',
        'woocommerce_pushed_at',
        'woocommerce_push_error',
        'raw',
        'reviewed_at',
    ];

    protected $casts = [
        'images'       => 'array',
        'videos'       => 'array',
        'raw'          => 'array',
        'reviewed_at'  => 'datetime',
        'replied_at'   => 'datetime',
        'opencart_pushed_at'    => 'datetime',
        'ventacart_pushed_at' => 'datetime',
        'woocommerce_pushed_at' => 'datetime',
    ];

    public function scopeShopee($query)
    {
        return $query->where('channel', 'shopee');
    }

    public function scopeLazada($query)
    {
        return $query->where('channel', 'lazada');
    }

    public function scopePending($query)
    {
        return $query->where('opencart_sync_status', 'pending');
    }

    public function scopePushed($query)
    {
        return $query->where('opencart_sync_status', 'pushed');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }
}
