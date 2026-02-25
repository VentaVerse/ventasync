<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopeeProfile extends Model
{
    protected $table = 'shopee_profiles';

    protected $fillable = [
        'name', 'catalog_category_ids', 'manufacturer_ids',
        'shopee_category_id', 'logistic_ids',
        'markup_fixed', 'markup_percent',
    ];

    protected $casts = [
        'catalog_category_ids' => 'array',
        'manufacturer_ids' => 'array',
        'logistic_ids' => 'array',
    ];

    public function profileAttributes()
    {
        return $this->hasMany(ShopeeProfileAttribute::class, 'shopee_profile_id');
    }
}
