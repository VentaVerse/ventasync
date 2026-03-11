<?php

namespace Extensions\opencart\Models;

use Illuminate\Database\Eloquent\Model;

class OpenCartProductGroup extends Model
{
    protected $table = 'opencart_product_groups';

    protected $fillable = ['opencart_setting_id', 'name', 'catalog_category_ids', 'manufacturer_ids'];

    protected $casts = [
        'catalog_category_ids' => 'array',
        'manufacturer_ids' => 'array',
    ];

    public function setting()
    {
        return $this->belongsTo(OpenCartSetting::class, 'opencart_setting_id');
    }

    public function groupProducts()
    {
        return $this->hasMany(OpenCartProductGroupProduct::class, 'opencart_product_group_id');
    }
}
