<?php

namespace Extensions\tiktok\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-product TikTok facts that have no other home (#146). NOT the
 * listing truth: whether a product is ON TikTok stays derived across
 * the group pivots. This record carries the discount rule - a percent
 * off, a window, and the promotion activity the product joined.
 */
class TikTokListing extends Model
{
    protected $table = 'tiktok_listings';

    protected $fillable = [
        'product_id',
        'sale_percent',
        'sale_starts_at',
        'sale_ends_at',
        'tiktok_activity_id',
    ];

    protected $casts = [
        'sale_percent' => 'float',
        'sale_starts_at' => 'datetime',
        'sale_ends_at' => 'datetime',
    ];
}
