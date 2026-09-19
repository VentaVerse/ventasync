<?php

namespace App\Integrations\Support;

use App\Models\ScheduledJob;

/**
 * Give a store, or a whole channel, the scheduled jobs it declares, once.
 *
 * Two shapes, one loop. On a multi-store channel (Venta, OpenCart,
 * WooCommerce) every store is a separate live storefront, so the jobs are per
 * store: --store is carried in options for App\Console\Kernel to pass on the
 * command line, and store_id scopes the row to the settings page that shows
 * it. A channel whose commands work across its stores themselves (Shopee,
 * Lazada, TikTok) keeps one set with no store_id.
 *
 * The seeding is identical for both, so it lives here rather than being
 * copied. Each channel supplies only its own list of jobs; a second copy of
 * this loop is how one of them ends up missing the idempotency check or
 * arriving enabled.
 */
class StoreScheduledJobs
{
    /**
     * Create any of $jobs the store, or the channel when $storeId is null,
     * does not already have.
     *
     * Idempotent, so running it twice adds nothing and a cadence or switch the
     * operator has changed is never reset. A job is found by its command; when
     * two jobs share a command (orders and returns both run sync-orders) its
     * name tells them apart as well.
     *
     * Jobs arrive DISABLED, always. Every channel points at real shops, so
     * creating a store, switching a channel on, or starting the application
     * must not begin writing to a live shop nobody has scheduled.
     *
     * @param  array<int, array{0: string, 1: string, 2: int, 3: string, 4?: array<string, mixed>}>  $jobs
     *         command, display name, cadence value, cadence unit (the unit
     *         column is enum('minute','hour','day') - singular), and optional
     *         command options.
     * @return int how many were created
     */
    public static function ensure(string $integration, ?int $storeId, array $jobs): int
    {
        $created = 0;
        $shared = array_count_values(array_map(fn (array $job) => $job[0], $jobs));

        foreach ($jobs as $declared) {
            [$command, $displayName, $value, $unit] = $declared;
            $options = $declared[4] ?? [];

            $existing = ScheduledJob::query()
                ->where('integration', $integration)
                ->where('command', $command);
            $storeId === null
                ? $existing->whereNull('store_id')
                : $existing->where('store_id', $storeId);
            if (($shared[$command] ?? 0) > 1) {
                $existing->where('display_name', $displayName);
            }

            if ($existing->exists()) {
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
                'options'       => $storeId === null ? $options : array_merge($options, ['store' => $storeId]),
            ]);

            $job->syncCronExpression();
            $job->save();
            $created++;
        }

        return $created;
    }
}
