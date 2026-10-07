<?php

namespace Extensions\lazada\Models;

use Illuminate\Database\Eloquent\Model;

class LazadaProductAttribute extends Model
{
    protected $table = 'lazada_product_attributes';

    protected $fillable = ['lazada_product_id', 'attribute_key', 'value'];
}
