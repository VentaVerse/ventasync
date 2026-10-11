<?php

namespace Extensions\OpenCart\Models;

use Illuminate\Database\Eloquent\Model;

class OpenCartProductGroupProduct extends Model
{
    protected $table = 'ch_opencart_product_group_products';

    public $timestamps = false;

    protected $fillable = ['opencart_product_group_id', 'product_id'];

    public function group()
    {
        return $this->belongsTo(OpenCartProductGroup::class, 'opencart_product_group_id');
    }
}
