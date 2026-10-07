<?php

namespace App\Integrations\Support;

use App\Models\ScheduledJob;
use Illuminate\Support\Collection;

final class AutomationSettings
{
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
