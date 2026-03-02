<?php

namespace App\Models;

use App\Models\Catalog\Product;
use Illuminate\Database\Eloquent\Model;

class MarketplaceReview extends Model
{
    protected $table = 'marketplace_reviews';

    protected $fillable = [
        'platform',
        'platform_review_id',
        'product_id',
        'platform_item_id',
        'platform_order_id',
        'author',
        'rating',
        'comment',
        'images',
        'videos',
        'reply',
        'replied_at',
        'oc_sync_status',
        'opencart_setting_id',
        'oc_review_id',
        'oc_pushed_at',
        'oc_push_error',
        'raw',
        'reviewed_at',
    ];

    protected $casts = [
        'images'       => 'array',
        'videos'       => 'array',
        'raw'          => 'array',
        'reviewed_at'  => 'datetime',
        'replied_at'   => 'datetime',
        'oc_pushed_at' => 'datetime',
    ];

    // ── Scopes ───────────────────────────────────────────

    public function scopeShopee($query)
    {
        return $query->where('platform', 'shopee');
    }

    public function scopeLazada($query)
    {
        return $query->where('platform', 'lazada');
    }

    public function scopePending($query)
    {
        return $query->where('oc_sync_status', 'pending');
    }

    public function scopePushed($query)
    {
        return $query->where('oc_sync_status', 'pushed');
    }

    // ── Relationships ────────────────────────────────────

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function opencartSetting()
    {
        return $this->belongsTo(OpenCartSetting::class, 'opencart_setting_id');
    }
}
