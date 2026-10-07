<?php

namespace Extensions\venta\Support;

use App\Integrations\Support\StoreScheduledJobs;

class VentaScheduledJobs
{
    public const JOBS = [
        ['venta:sync orders', 'Sync Orders', 5, 'minute'],
        ['venta:push-stock', 'Push Stock', 15, 'minute'],
        ['venta:push-price', 'Push Price', 30, 'minute'],
        ['venta:push-reviews', 'Push Reviews', 1, 'day'],
        ['venta:refresh-listing-status', 'Refresh Listing Status', 30, 'minute'],
    ];

    public static function ensureFor(int $storeId): int
    {
        return StoreScheduledJobs::ensure('venta', $storeId, self::JOBS);
    }
}
