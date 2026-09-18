<?php

namespace App\Integrations\Listings;

/**
 * The store a channel request is acting for.
 *
 * Every multi-store channel binds its resolved store as `{channel}.route-store`
 * in its own middleware, and one channel (Venta) leaves the segment in the
 * route instead. Anything shared that needs to know which store it is
 * working for asks here rather than reaching for whichever local variable
 * happens to hold it, so the five channels answer the question one way.
 */
final class ListingStore
{
    public static function id(string $integration): int
    {
        $key = $integration . '.route-store';
        if (app()->bound($key)) {
            return (int) (app($key)->id ?? 0);
        }

        return (int) (request()->route('store') ?? 0);
    }
}
