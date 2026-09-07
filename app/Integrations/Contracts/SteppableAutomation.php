<?php

namespace App\Integrations\Contracts;

use App\Models\ScheduledJob;

/**
 * A scheduled command that Run now can walk in short steps instead of
 * one long request (the owner, 2026-09-07). The command names its units
 * (products, orders) and does the work for a handful of them per call;
 * App\Services\AutomationRunner drives it and keeps the count. The cron
 * still runs the command whole through handle().
 */
interface SteppableAutomation
{
    /**
     * Everything this run will touch, in order. A unit is an opaque string
     * the step understands ("storeId:productId").
     *
     * @return array{label: string, units: list<string>}
     */
    public function automationUnits(ScheduledJob $job): array;

    /**
     * Do the work for these units.
     *
     * @param list<string> $units
     * @return array{ok: int, failed: int, note?: string, error?: string}
     *         error: the run stops on it; ok and failed count units
     */
    public function automationStep(ScheduledJob $job, array $units): array;
}
