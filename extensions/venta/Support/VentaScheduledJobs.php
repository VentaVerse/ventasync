<?php

namespace Extensions\venta\Support;

use App\Integrations\Support\StoreScheduledJobs;

/**
 * The three jobs every Venta store runs.
 *
 * The cadences are the ones Venta's own Scheduling tab recommended in prose
 * before these became real rows.
 */
class VentaScheduledJobs
{
    /** command, display name, cadence value, cadence unit. */
    public const JOBS = [
        ['venta:sync orders', 'Sync Orders', 5, 'minute'],
        ['venta:push-stock', 'Push Stock', 15, 'minute'],
        ['venta:push-price', 'Push Price', 30, 'minute'],
        ['venta:push-reviews', 'Push Reviews', 1, 'day'],
        // The listing status mirror (#166 on Venta, #171): the same
        // cadence the three marketplaces refresh theirs on.
        ['venta:refresh-listing-status', 'Refresh Listing Status', 30, 'minute'],
    ];

    public static function ensureFor(int $storeId): int
    {
        return StoreScheduledJobs::ensure('venta', $storeId, self::JOBS);
    }
}
