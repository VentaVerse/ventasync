<?php

namespace App\Integrations\Contracts;

use App\Services\Orders\Breakdown\OrderBreakdown;

/**
 * A channel that shows one of its orders' money the way its own store shows
 * it. The order page draws the answer in place of its plain Sale section;
 * without an answer (the channel is not installed, or the store has not
 * reported yet) the Sale section shows.
 */
interface OrderBreakdownSource
{
    /** The breakdown for a catalog order this channel owns, or null. */
    public function orderBreakdown(int $coreOrderId): ?OrderBreakdown;
}
