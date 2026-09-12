<?php

namespace App\Integrations\Contracts;

/**
 * A channel that can say how one of its orders was sold, line by line, from
 * what it already stores for the order. Used to rewrite an order's Sale lines
 * without re-syncing the order.
 */
interface SaleLinesSource
{
    /**
     * The sale lines for a catalog order this channel owns, in the order's
     * currency, or null when the order is not this channel's.
     *
     * $itemsSubtotal and $shipping are the order's own figures, in its
     * currency, for when the channel's full breakdown is not known yet.
     *
     * @return list<array{code: string, title: string, value: float}>|null
     */
    public function saleLinesForOrder(int $coreOrderId, float $itemsSubtotal, float $shipping): ?array;
}
