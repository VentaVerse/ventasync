<?php

namespace Extensions\Lazada\Models;

use Illuminate\Database\Eloquent\Model;

class LazadaProductGroupAttribute extends Model
{
    protected $table = 'ch_lazada_product_group_attributes';

    protected $fillable = ['lazada_product_group_id', 'attribute_key', 'value'];
}
