<?php

namespace Extensions\lazada\Models;

use Illuminate\Database\Eloquent\Model;

class LazadaReverseOrder extends Model
{
    protected $table = 'lazada_reverse_orders';

    protected $fillable = [
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

    /**
     * When the SELLER has to act on this return, as a Unix timestamp.
     *
     * Lazada calls it `sla` and documents it as "seller operation sla in
     * milliseconds", on each line of reverse_order_lines. It appears in both
     * /reverse/getreverseordersforseller and /order/reverse/return/detail/list.
     * See docs/marketplace-api/lazada-getreverseordersforseller.txt at the
     * workspace root - the doc site is a JavaScript app that no plain fetch
     * can read, so it was rendered with tools/fetch-doc.sh.
     *
     * NOT SEEN IN OUR OWN DATA YET. None of the 76 reverse orders on file
     * carries the field, which is why this was first reported as "Lazada has
     * no deadline" - that was reading our sandbox rather than the API. The
     * accessor returns null when it is absent, so the chip simply does not
     * render until real data brings one. That is the safe direction: no
     * countdown is honest, a wrong countdown is not.
     *
     * One order can hold several lines, each with its own clock. The EARLIEST
     * is the order's deadline, because that is the one that expires first and
     * the operator acts on the order, not on a line.
     */
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

            // Documented as milliseconds. Left as-is it would render as a date
            // in the year 57000 and read as "never overdue" forever.
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
