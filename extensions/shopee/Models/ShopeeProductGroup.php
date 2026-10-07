<?php

namespace Extensions\shopee\Models;

use Illuminate\Database\Eloquent\Model;

class ShopeeProductGroup extends Model
{
    use \Extensions\shopee\Models\Concerns\BelongsToShopeeStore;

    protected $table = 'shopee_product_groups';

    protected $fillable = [
        'watermark_template_id',
        'shopee_setting_id',
        'name',
        'shopee_category_id', 'shopee_brand_id', 'logistic_ids',
        'markup_fixed', 'markup_percent',
    ];

    protected $casts = [
        'logistic_ids' => 'array',
    ];

    public function groupAttributes()
    {
        return $this->hasMany(ShopeeProductGroupAttribute::class, 'shopee_product_group_id');
    }

    public function groupProducts()
    {
        return $this->hasMany(ShopeeProductGroupProduct::class, 'shopee_product_group_id');
    }
}
