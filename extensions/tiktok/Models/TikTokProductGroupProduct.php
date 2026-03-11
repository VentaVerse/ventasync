<?php

namespace Extensions\tiktok\Models;

use Illuminate\Database\Eloquent\Model;

class TikTokProductGroupProduct extends Model
{
    protected $table = 'tiktok_product_group_products';

    public $timestamps = false;

    protected $fillable = [
        'tiktok_product_group_id', 'product_id', 'tiktok_product_id',
        'tiktok_sku_id', 'sync_status', 'last_pushed_at', 'push_error',
    ];

    protected $casts = [
        'last_pushed_at' => 'datetime',
    ];

    public function group()
    {
        return $this->belongsTo(TikTokProductGroup::class, 'tiktok_product_group_id');
    }
}
