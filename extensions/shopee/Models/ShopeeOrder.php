<?php

namespace Extensions\shopee\Models;

use App\Models\Catalog\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopeeOrder extends Model
{
    protected $table = 'shopee_orders';

    protected $fillable = [
        'region',
        'order_sn',
        'status',
        'order_created_at',
        'order_updated_at',
        'raw',
        'fees',
        'buyer_invoice',
        'payout_status',
        'paid_at',
        'catalog_order_id',
    ];

    protected $hidden = ['raw'];

    protected $casts = [
        'raw' => 'array',
        'fees' => 'array',
        'buyer_invoice' => 'array',
        'order_created_at' => 'datetime',
        'order_updated_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(ShopeeOrderProduct::class, 'shopee_order_id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(ShopeeReturn::class, 'shopee_order_id');
    }

    public function catalogOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'catalog_order_id', 'order_id');
    }
}
