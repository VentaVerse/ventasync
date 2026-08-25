<?php

namespace Extensions\tiktok\Models;

use Illuminate\Database\Eloquent\Model;

/** One of a group's attribute answers, keyed by TikTok's attribute id. */
class TikTokProductGroupAttribute extends Model
{
    protected $table = 'tiktok_product_group_attributes';

    protected $fillable = ['tiktok_product_group_id', 'attribute_key', 'value'];
}
