<?php

namespace Extensions\Shopee\Models;

use Extensions\Shopee\Models\Concerns\BelongsToShopeeStore;
use Illuminate\Database\Eloquent\Model;

class ShopeeListingTemplate extends Model
{
    use BelongsToShopeeStore;

    protected $table = 'ch_shopee_listing_templates';

    protected $fillable = [
        'store_id', 'name', 'shopee_category_id', 'shopee_brand_id', 'logistic_ids',
        'markup_percent', 'markup_fixed', 'watermark_template_id',
    ];

    protected $casts = [
        'logistic_ids' => 'array',
    ];

    public function templateAttributes()
    {
        return $this->hasMany(ShopeeListingTemplateAttribute::class, 'listing_template_id');
    }
}
