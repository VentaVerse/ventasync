<?php

namespace Extensions\shopee\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopeeReturn extends Model
{
    use \Extensions\shopee\Models\Concerns\BelongsToShopeeStore;

    protected $table = 'shopee_returns';

    protected $fillable = [
        'shopee_setting_id',
        'region',
        'return_sn',
        'order_sn',
        'shopee_order_id',
        'status',
        'needs_logistics',
        'return_solution',
        'reverse_logistics_status',
        'is_arrived_at_warehouse',
        'seller_compensation_status',
        'reason',
        'reason_text',
        'refund_amount',
        'currency',
        'items',
        'negotiation',
        'raw',
        'return_created_at',
        'return_updated_at',
    ];

    protected $hidden = ['raw'];

    protected $casts = [
        'items' => 'array',
        'negotiation' => 'array',
        'raw' => 'array',
        'refund_amount' => 'decimal:2',
        'needs_logistics' => 'boolean',
        'is_arrived_at_warehouse' => 'boolean',
        'return_created_at' => 'datetime',
        'return_updated_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(ShopeeOrder::class, 'shopee_order_id');
    }

    // return_seller_due_date is the seller's clock; 0 or absent means nothing to answer, so return null.
    public function sellerDueAt(): ?int
    {
        $raw = is_array($this->raw) ? $this->raw : [];
        $due = $raw['return_seller_due_date'] ?? null;

        if (! is_numeric($due)) {
            return null;
        }

        $due = (int) $due;

        if ($due <= 0) {
            return null;
        }

        if ($due > 1000000000000) {
            $due = intdiv($due, 1000);
        }

        return $due;
    }
}
