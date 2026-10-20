<?php

namespace Extensions\VentaCart\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VentaCartProductGroup extends Model
{
    protected $table = 'ch_ventacart_product_groups';

    protected $fillable = ['store_id', 'name'];

    public function store(): BelongsTo
    {
        return $this->belongsTo(VentaCartStore::class, 'store_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(VentaCartProductGroupProduct::class, 'ventacart_product_group_id');
    }
}
