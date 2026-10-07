<?php

namespace Extensions\tiktok\Models;

use Illuminate\Database\Eloquent\Model;
use Extensions\tiktok\Models\Concerns\BelongsToTikTokStore;

class TikTokProductGroup extends Model
{
    use BelongsToTikTokStore;

    protected $table = 'tiktok_product_groups';

    protected $fillable = [
        'watermark_template_id',
        'tiktok_setting_id', 'name', 'tiktok_category_id',
        'markup_percent', 'markup_fixed',
    ];

    protected $casts = [
    ];

    public function category()
    {
        return $this->belongsTo(TikTokCategory::class, 'tiktok_category_id');
    }

    public function groupProducts()
    {
        return $this->hasMany(TikTokProductGroupProduct::class, 'tiktok_product_group_id');
    }

    public function applyMarkup(float $price): float
    {
        return round($price + ($price * (float) ($this->markup_percent ?? 0) / 100) + (float) ($this->markup_fixed ?? 0), 2);
    }

    public function attributes()
    {
        return $this->hasMany(TikTokProductGroupAttribute::class, 'tiktok_product_group_id');
    }
}
