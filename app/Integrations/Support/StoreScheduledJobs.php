<?php

namespace App\Integrations\Support;

use App\Models\ScheduledJob;

class StoreScheduledJobs
{
    // Jobs are created disabled: they point at real shops and must not write until someone enables them.
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
