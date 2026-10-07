<?php

namespace Extensions\opencart\Models;

use Illuminate\Database\Eloquent\Model;

class OpenCartProductLink extends Model
{
    protected $table = 'opencart_product_links';

    protected $fillable = [
        'opencart_setting_id',
        'oc_product_id',
        'product_id',
        'sku',
    ];

    public function setting()
    {
        return $this->belongsTo(OpenCartSetting::class, 'opencart_setting_id');
    }
}
