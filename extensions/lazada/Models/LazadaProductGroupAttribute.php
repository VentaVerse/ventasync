<?php

namespace Extensions\lazada\Models;

use Illuminate\Database\Eloquent\Model;

class LazadaProductGroupAttribute extends Model
{
    protected $table = 'lazada_product_group_attributes';

    protected $fillable = ['lazada_product_group_id', 'attribute_key', 'value'];
}
