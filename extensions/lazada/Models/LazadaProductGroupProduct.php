<?php

namespace Extensions\lazada\Models;

use Illuminate\Database\Eloquent\Model;

class LazadaProductGroupProduct extends Model
{
    protected $table = 'lazada_product_group_products';

    public $timestamps = false;

    protected $fillable = [
        'lazada_product_group_id', 'lazada_product_id', 'product_id',
        'sync_status', 'last_pushed_at', 'push_error',
    ];

    protected $casts = [
        'last_pushed_at' => 'datetime',
    ];

    public function group()
    {
        return $this->belongsTo(LazadaProductGroup::class, 'lazada_product_group_id');
    }

    public function lazadaProduct()
    {
        return $this->belongsTo(LazadaProduct::class, 'lazada_product_id');
    }
}
