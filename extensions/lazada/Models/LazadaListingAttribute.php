<?php

namespace Extensions\Lazada\Models;

use Illuminate\Database\Eloquent\Model;

class LazadaListingAttribute extends Model
{
    protected $table = 'ch_lazada_listing_attributes';

    protected $fillable = ['lazada_listing_id', 'attribute_key', 'value'];
}
