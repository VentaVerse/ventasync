<?php

namespace Extensions\venta\Support;

use App\Models\ScheduledJob;

/**
 * The three jobs every Venta store runs, defined once.
 *
 * Venta is multi-store and each store is a separate live storefront, so the
 * jobs are per store: the --store option is carried in options so
 * App\Console\Kernel passes it on the command line, and store_id scopes the
 * row to the settings page that shows it.
 *
 * Both the seeding migration and the store-create path call this, because a
 * store added tomorrow needs the same three rows as one that existed when the
 * migration ran. Without that, a new store's Automations tab is empty forever
 * and its syncs never run, with nothing on screen explaining why.
 */
class VentaScheduledJobs
{
    /** command, display name, cadence value, cadence unit (enum: minute|hour|day). */
    private const JOBS = [
        ['venta:sync orders', 'Sync Orders', 5, 'minute'],
        ['venta:push-stock', 'Push Stock & Price', 15, 'minute'],
        ['venta:push-reviews', 'Push Reviews', 1, 'day'],
    ];

    /**
     * Give a store its jobs if it has none. Returns how many were created.
     *
     * Idempotent by (integration, store_id, command), so running it twice adds
     * nothing and an operator's edited cadence is never reset by a later call.
     */
    public static function ensureFor(int $storeId): int
    {
        $created = 0;

        foreach (self::JOBS as [$command, $displayName, $value, $unit]) {
            $exists = ScheduledJob::query()
                ->where('integration', 'venta')
                ->where('store_id', $storeId)
                ->where('command', $command)
                ->exists();

            if ($exists) {
                continue;
            }

            $job = new ScheduledJob([
                'integration'   => 'venta',
                'store_id'      => $storeId,
                'display_name'  => $displayName,
                'command'       => $command,
                'cadence_value' => $value,
                'cadence_unit'  => $unit,
                // Off on arrival, deliberately. Venta has no sandbox and
                // base_url is the real storefront, so creating a store must
                // not start writing to a live shop nobody has scheduled yet.
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
