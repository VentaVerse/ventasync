<?php

namespace Extensions\tiktok\Models;

use Illuminate\Database\Eloquent\Model;

class TikTokProductGroup extends Model
{
    protected $table = 'tiktok_product_groups';

    protected $fillable = [
        'name', 'tiktok_category_id', 'catalog_category_ids', 'manufacturer_ids',
        'markup_type', 'markup_value',
    ];

    protected $casts = [
        'catalog_category_ids' => 'array',
        'manufacturer_ids' => 'array',
        'markup_value' => 'decimal:2',
    ];

    public function category()
    {
        return $this->belongsTo(TikTokCategory::class, 'tiktok_category_id');
    }

    public function groupProducts()
    {
        return $this->hasMany(TikTokProductGroupProduct::class, 'tiktok_product_group_id');
    }

    /**
     * Apply markup to a base price.
     */
    public function applyMarkup(float $price): float
    {
        if (!$this->markup_type || !$this->markup_value) {
            return $price;
        }

        if ($this->markup_type === 'percent') {
            return $price + ($price * (float) $this->markup_value / 100);
        }

        // fixed
        return $price + (float) $this->markup_value;
    }
}
