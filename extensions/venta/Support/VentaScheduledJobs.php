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
        ['venta:push-stock', 'Push Stock & Price', 15, 'minute'],
        ['venta:push-reviews', 'Push Reviews', 1, 'day'],
    ];

    public static function ensureFor(int $storeId): int
    {
        return StoreScheduledJobs::ensure('venta', $storeId, self::JOBS);
    }
}
