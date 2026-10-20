<?php

namespace Extensions\TikTok\Models;

use Illuminate\Database\Eloquent\Model;

class TikTokListingTemplateAttribute extends Model
{
    protected $table = 'ch_tiktok_listing_template_attributes';

    protected $fillable = ['listing_template_id', 'attribute_key', 'value'];
}
