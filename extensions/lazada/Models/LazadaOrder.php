<?php

namespace Extensions\lazada\Models;

use App\Models\Catalog\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LazadaOrder extends Model
{
    protected $table = 'lazada_orders';

    protected $fillable = [
        'region',
        'order_id',
        'status',
        'order_created_at',
        'order_updated_at',
        'raw',
        'fees',
        'payout_status',
        'paid_at',
        'catalog_order_id',
    ];

    protected $hidden = ['raw'];

    protected $casts = [
        'raw' => 'array',
        'fees' => 'array',
        'order_created_at' => 'datetime',
        'order_updated_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(LazadaOrderProduct::class, 'lazada_order_id');
    }

    public function catalogOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'catalog_order_id', 'order_id');
    }
}
