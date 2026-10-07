<?php

namespace Extensions\ventacart\Support;

use App\Integrations\Support\StoreScheduledJobs;

class VentaCartScheduledJobs
{
    public const JOBS = [
        ['ventacart:sync orders', 'Sync Orders', 5, 'minute'],
        ['ventacart:push-stock', 'Push Stock', 15, 'minute'],
        ['ventacart:push-price', 'Push Price', 30, 'minute'],
        ['ventacart:push-reviews', 'Push Reviews', 1, 'day'],
        ['ventacart:refresh-listing-status', 'Refresh Listing Status', 30, 'minute'],
    ];

    public static function ensureFor(int $storeId): int
    {
        return StoreScheduledJobs::ensure('ventacart', $storeId, self::JOBS);
    }
}
