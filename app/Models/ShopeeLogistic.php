<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopeeLogistic extends Model
{
    protected $table = 'shopee_logistics';

    protected $guarded = [];

    protected $casts = [
        'cod_enabled' => 'boolean',
        'enabled' => 'boolean',
        'force_enable' => 'boolean',
        'support_pre_order' => 'boolean',
        'support_cross_border' => 'boolean',
        'weight_limit' => 'array',
        'item_max_dimension' => 'array',
        'volume_limit' => 'array',
        'raw_data' => 'array',
    ];
}
