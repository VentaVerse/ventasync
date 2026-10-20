<?php

namespace Extensions\Lazada\Models;

use Illuminate\Database\Eloquent\Model;

class LazadaListingTemplateAttribute extends Model
{
    protected $table = 'ch_lazada_listing_template_attributes';

    protected $fillable = ['listing_template_id', 'attribute_key', 'value'];
}
