<?php

namespace Extensions\TikTok\Models;

use Illuminate\Database\Eloquent\Model;

class TikTokProductGroupAttribute extends Model
{
    protected $table = 'ch_tiktok_product_group_attributes';

    protected $fillable = ['tiktok_product_group_id', 'attribute_key', 'value'];
}
