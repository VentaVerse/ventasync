<?php

namespace Extensions\OpenCart\Support;

use App\Channels\Support\StoreScheduledJobs;

class OpenCartScheduledJobs
{
    public const JOBS = [
        ['opencart:sync orders', 'Sync Orders', 5, 'minute'],
        ['opencart:push-qty', 'Push Stock', 15, 'minute'],
        ['opencart:push-price', 'Push Price', 30, 'minute'],
        ['opencart:push-reviews', 'Push Reviews', 1, 'day'],
    ];

    public static function ensureFor(int $storeId): int
    {
        return StoreScheduledJobs::ensure('opencart', $storeId, self::JOBS);
    }
}
