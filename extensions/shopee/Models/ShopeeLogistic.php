<?php

namespace Extensions\shopee\Models;

use Illuminate\Database\Eloquent\Model;

class ShopeeLogistic extends Model
{
    protected $table = 'shopee_logistics';

    protected $fillable = [
        'logistics_channel_id', 'logistics_channel_name', 'cod_enabled',
        'enabled', 'force_enable', 'fee_type', 'weight_limit',
        'item_max_dimension', 'volume_limit', 'mask_channel_id',
        'logistics_description', 'support_pre_order', 'support_cross_border', 'raw_data',
    ];

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
