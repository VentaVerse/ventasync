<?php

namespace App\Integrations\Support;

use App\Models\ScheduledJob;
use Illuminate\Support\Collection;

/**
 * Writes the Automations rows a form submitted: switch, cadence and the
 * per-job look-back options. Callers decide which jobs the person may change.
 */
final class AutomationSettings
{
    /** Rules for the `jobs` field of a form that carries Automations rows. */
    public static function rules(): array
    {
        return [
            'jobs' => ['nullable', 'array'],
            'jobs.*.cadence_value' => ['required', 'integer', 'min:1', 'max:9999'],
            'jobs.*.cadence_unit' => ['required', 'in:minute,hour,day'],
            'jobs.*.enabled' => ['nullable'],
            'jobs.*.days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'jobs.*.days_returns' => ['nullable', 'integer', 'min:1', 'max:365'],
        ];
    }

    /**
     * @param Collection<int, ScheduledJob> $jobs keyed by id
     * @param array<int|string, array<string, mixed>> $payloads keyed by job id
     * @return int how many were saved
     */
    public static function apply(Collection $jobs, array $payloads): int
    {
        $saved = 0;
        foreach ($payloads as $id => $payload) {
            $job = $jobs[(int) $id] ?? null;
            if (! $job) {
                continue;
            }

            $job->cadence_value = (int) $payload['cadence_value'];
            $job->cadence_unit = (string) $payload['cadence_unit'];
            $job->enabled = ! empty($payload['enabled']);

            $options = is_array($job->options) ? $job->options : [];
            foreach (['days', 'days_returns'] as $key) {
                if (isset($payload[$key]) && $payload[$key] !== '') {
                    $options[$key] = (int) $payload[$key];
                }
            }
            $job->options = $options;

            $job->syncCronExpression();
            $job->save();
            $saved++;
        }

        return $saved;
    }
}
