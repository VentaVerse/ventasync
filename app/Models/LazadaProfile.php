<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LazadaProfile extends Model
{
    protected $table = 'lazada_profiles';

    protected $fillable = [
        'name', 'catalog_category_ids', 'manufacturer_ids',
        'lazada_category_id', 'brand_id', 'brand_name_override',
        'markup_fixed', 'markup_percent',
    ];

    protected $casts = [
        'catalog_category_ids' => 'array',
        'manufacturer_ids' => 'array',
    ];

    public function profileAttributes()
    {
        return $this->hasMany(LazadaProfileAttribute::class, 'lazada_profile_id');
    }

    public function products()
    {
        return $this->belongsToMany(LazadaProduct::class, 'lazada_product_profile');
    }
}
