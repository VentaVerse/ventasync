<?php

namespace Extensions\lazada\Models;

use Illuminate\Database\Eloquent\Model;
use Extensions\lazada\Models\Concerns\BelongsToLazadaStore;

class LazadaReverseOrder extends Model
{
    use BelongsToLazadaStore;

    protected $table = 'lazada_reverse_orders';

    protected $fillable = [
        'lazada_setting_id',
        'region',
        'reverse_order_id',
        'trade_order_id',
        'reverse_status',
        'reverse_type',
        'reason',
        'refund_amount',
        'currency',
        'return_created_at',
        'items',
        'raw',
    ];

    protected $hidden = ['raw'];

    protected $casts = [
        'return_created_at' => 'datetime',
        'items' => 'array',
        'raw' => 'array',
        'refund_amount' => 'decimal:2',
    ];

    // Lazada's sla is in milliseconds per line; the earliest line is the order's deadline.
    public function sellerDueAt(): ?int
    {
        $raw = is_array($this->raw) ? $this->raw : [];
        $lines = $raw['reverse_order_lines'] ?? [];

        if (! is_array($lines)) {
            return null;
        }

        $earliest = null;

        foreach ($lines as $line) {
            $sla = is_array($line) ? ($line['sla'] ?? null) : null;

            if (! is_numeric($sla)) {
                continue;
            }

            $sla = (int) $sla;

            if ($sla <= 0) {
                continue;
            }

            if ($sla > 1000000000000) {
                $sla = intdiv($sla, 1000);
            }

            if ($earliest === null || $sla < $earliest) {
                $earliest = $sla;
            }
        }

        return $earliest;
    }
}
