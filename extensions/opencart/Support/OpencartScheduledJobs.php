<?php

namespace Extensions\opencart\Support;

use App\Integrations\Support\StoreScheduledJobs;

/**
 * The three jobs every OpenCart store runs.
 *
 * Command list and cadences carried over verbatim from the copy-paste cron
 * block this tab used to print, so nothing about WHICH jobs run or how often
 * was decided here - only that the ERP now runs them instead of asking the
 * operator to paste them into a hosting panel.
 */
class OpencartScheduledJobs
{
    /** command, display name, cadence value, cadence unit. */
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
