<?php

namespace Extensions\tiktok\Models;

use Illuminate\Database\Eloquent\Model;

class TikTokProductGroup extends Model
{
    protected $table = 'tiktok_product_groups';

    protected $fillable = [
        'name', 'tiktok_category_id', 'catalog_category_ids', 'manufacturer_ids',
        'markup_percent', 'markup_fixed',
    ];

    protected $casts = [
        'catalog_category_ids' => 'array',
        'manufacturer_ids' => 'array',
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
        $pct = (float) ($this->markup_percent ?? 0);
        $fix = (float) ($this->markup_fixed ?? 0);

        if ($pct > 0) {
            $price = $price + ($price * $pct / 100);
        }
        if ($fix > 0) {
            $price = $price + $fix;
        }

        return $price;
    }
}
