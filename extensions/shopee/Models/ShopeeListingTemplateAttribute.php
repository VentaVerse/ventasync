<?php

namespace Extensions\Shopee\Models;

use Illuminate\Database\Eloquent\Model;

class ShopeeListingTemplateAttribute extends Model
{
    protected $table = 'ch_shopee_listing_template_attributes';

    protected $fillable = ['listing_template_id', 'attribute_key', 'value'];
}
