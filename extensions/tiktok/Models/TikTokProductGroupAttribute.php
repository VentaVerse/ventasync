<?php

namespace Extensions\tiktok\Models;

use Illuminate\Database\Eloquent\Model;

class TikTokProductGroupAttribute extends Model
{
    protected $table = 'tiktok_product_group_attributes';

    protected $fillable = ['tiktok_product_group_id', 'attribute_key', 'value'];
}
