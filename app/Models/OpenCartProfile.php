<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpenCartProfile extends Model
{
    protected $table = 'opencart_profiles';

    protected $fillable = ['opencart_setting_id', 'name', 'catalog_category_ids', 'manufacturer_ids'];

    protected $casts = [
        'catalog_category_ids' => 'array',
        'manufacturer_ids' => 'array',
    ];

    public function setting()
    {
        return $this->belongsTo(OpenCartSetting::class, 'opencart_setting_id');
    }

    public function profileProducts()
    {
        return $this->hasMany(OpenCartProfileProduct::class, 'opencart_profile_id');
    }
}
