<?php

namespace Extensions\lazada\Models;

use Illuminate\Database\Eloquent\Model;
use Extensions\lazada\Models\Concerns\BelongsToLazadaStore;

class LazadaProductGroup extends Model
{
    use BelongsToLazadaStore;

    protected $table = 'lazada_product_groups';

    protected $fillable = [
        'watermark_template_id',
        'lazada_setting_id',
        'name',
        'lazada_category_id', 'brand_id', 'brand_name_override',
        'markup_fixed', 'markup_percent',
    ];

    protected $casts = [
    ];

    public function groupAttributes()
    {
        return $this->hasMany(LazadaProductGroupAttribute::class, 'lazada_product_group_id');
    }

    public function products()
    {
        return $this->belongsToMany(LazadaProduct::class, 'lazada_product_group_products', 'lazada_product_group_id', 'lazada_product_id');
    }

    public function groupProducts()
    {
        return $this->hasMany(LazadaProductGroupProduct::class, 'lazada_product_group_id');
    }
}
