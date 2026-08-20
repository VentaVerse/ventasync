<?php

namespace Extensions\shopee\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopeeReturn extends Model
{
    protected $table = 'shopee_returns';

    protected $fillable = [
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

    /**
     * When the SELLER has to answer this return, as a Unix timestamp.
     *
     * Shopee sends four clocks on a return and only this one is the seller's:
     *
     *   return_seller_due_date  - you must respond by this          <- ours
     *   return_ship_due_date    - the BUYER must ship it back by this
     *   due_date               - the return's general clock
     *   negotiation.offer_due_date - the counter-offer window
     *
     * Showing the buyer's shipping deadline to a seller would be a countdown
     * on someone else's obligation, which is worse than no countdown at all.
     *
     * Shopee sets this conditionally: it is present while there is something
     * for you to answer and 0 once there is not, so a CANCELLED return has no
     * seller clock. 0 and absent mean the same thing here - nothing to show -
     * and both return null rather than 1970.
     *
     * Lazada has no equivalent. Its reverse-order payload carries creation and
     * modification times and no deadline of any kind, which is why the Lazada
     * returns list shows no countdown and should not pretend to.
     */
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

        // Shopee sends seconds. A millisecond value would render as a date in
        // the year 55000 rather than failing, so it is converted, not trusted.
        if ($due > 1000000000000) {
            $due = intdiv($due, 1000);
        }

        return $due;
    }
}
