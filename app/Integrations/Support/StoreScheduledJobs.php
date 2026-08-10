<?php

namespace App\Integrations\Support;

use App\Models\ScheduledJob;

/**
 * Give a store the scheduled jobs its channel expects, once.
 *
 * Venta and OpenCart are both multi-store, and on both every store is a
 * separate live storefront, so their jobs are per store: --store is carried in
 * options for App\Console\Kernel to pass on the command line, and store_id
 * scopes the row to the settings page that shows it.
 *
 * The seeding itself is identical for both, so it lives here rather than being
 * copied. Each channel supplies only its own list of jobs; a second copy of
 * this loop is how one of them ends up missing the idempotency check or
 * arriving enabled.
 */
class StoreScheduledJobs
{
    /**
     * Create any of $jobs the store does not already have.
     *
     * Idempotent by (integration, store_id, command), so running it twice adds
     * nothing and a cadence the operator has edited is never reset.
     *
     * Jobs arrive DISABLED, always. Neither channel has a sandbox and both
     * point at real storefronts, so creating a store, or running a migration,
     * must not start writing to a live shop nobody has scheduled.
     *
     * @param  array<int, array{0: string, 1: string, 2: int, 3: string}>  $jobs
     *         command, display name, cadence value, cadence unit
     *         (the unit column is enum('minute','hour','day') - singular).
     * @return int how many were created
     */
    public static function ensure(string $integration, int $storeId, array $jobs): int
    {
        $created = 0;

        foreach ($jobs as [$command, $displayName, $value, $unit]) {
            $exists = ScheduledJob::query()
                ->where('integration', $integration)
                ->where('store_id', $storeId)
                ->where('command', $command)
                ->exists();

            if ($exists) {
                continue;
            }

            $job = new ScheduledJob([
                'integration'   => $integration,
                'store_id'      => $storeId,
                'display_name'  => $displayName,
                'command'       => $command,
                'cadence_value' => $value,
                'cadence_unit'  => $unit,
                'enabled'       => false,
                'options'       => ['store' => $storeId],
            ]);

            $job->syncCronExpression();
            $job->save();
            $created++;
        }

        return $created;
    }
}
