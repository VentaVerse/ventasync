<?php

namespace App\Integrations\Contracts;

use App\Models\Catalog\Order;

/**
 * Optional sub-contract for integrations that can resolve marketplace-side
 * fees (commission, payment, shipping, transaction tax, etc.) for a catalog
 * order whose `marketplace_source` matches the integration.
 *
 * Each contributor inspects the Order's marketplace_source and either returns
 * a fees array or null (meaning "this order isn't mine"). Core's
 * OrderController iterates contributors and uses the first non-null result.
 *
 * Return shape:
 *   [
 *     'total' => float,
 *     'items' => [ ['label' => string, 'amount' => float], ... ],
 *     'source' => string,   // marketplace identifier, e.g. 'lazada'
 *   ]
 */
interface OrderFeesContributor
{
    /**
     * Return the marketplace-specific fee breakdown for the given order, or
     * null if this contributor doesn't recognise the order's source. Manual
     * fees from the order_fees table are merged in by the caller — this
     * method should only return the marketplace-derived fees.
     */
    public function feesForOrder(Order $order): ?array;
}
