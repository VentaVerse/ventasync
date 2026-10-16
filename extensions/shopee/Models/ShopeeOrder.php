<?php

namespace Extensions\Shopee\Models;

use App\Models\Catalog\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopeeOrder extends Model
{
    use \Extensions\Shopee\Models\Concerns\BelongsToShopeeStore;

    protected $table = 'ch_shopee_orders';

    protected $fillable = [
        'store_id',
        'region',
        'order_sn',
        'status',
        'order_created_at',
        'order_updated_at',
        'raw',
        'fees',
        'customer_invoice',
        'payout_status',
        'paid_at',
        'sales_order_id',
    ];

    protected $hidden = ['raw'];

    protected $casts = [
        'raw' => 'array',
        'fees' => 'array',
        'customer_invoice' => 'array',
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

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'sales_order_id', 'order_id');
    }
}
