<?php

namespace App\Support;

/**
 * How a store is named anywhere outside its own channel card or workspace:
 * "{Channel}: {store name}" (the owner, 2026-09-06).
 *
 * Store names repeat across channels (a seller running "Shop A" on
 * Venta and on OpenCart), and a channel name alone says nothing once it has
 * two stores. The sidebar, the fulfilment tabs, the orders list's channel
 * column, the dashboard's ranking, the order source filter and the
 * workspace tab title all read the same shape from here, so no surface
 * spells it differently.
 */
final class StoreLabel
{
    public static function of(string $channel, ?string $store): string
    {
        $channel = trim($channel);
        $store = trim((string) $store);

        if ($store === '' || strcasecmp($store, $channel) === 0) {
            return $channel;
        }
        // Already in the shape (a label built once and passed on).
        if (stripos($store, $channel . ': ') === 0) {
            return $store;
        }

        return $channel . ': ' . $store;
    }
}
