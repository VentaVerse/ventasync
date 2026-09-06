<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per (command, integration[, store_id]) tuple. The
 * Console\Kernel iterates enabled rows on every boot of the artisan
 * scheduler and registers each against Laravel's scheduler with the
 * stored cron expression. The Automations tab on each integration
 * page edits these rows.
 */
class ScheduledJob extends Model
{
    protected $table = 'scheduled_jobs';

    protected $guarded = [];

    protected $casts = [
        'enabled'        => 'boolean',
        'options'        => 'array',
        'last_run_at'    => 'datetime',
        'last_run_ok'    => 'boolean',
    ];

    /**
     * Convert "every N {unit}" → standard cron expression. Mirrors the
     * helper in the seed migration so both code paths produce identical
     * strings.
     */
    public static function expressionFor(int $value, string $unit): string
    {
        $value = max(1, $value);
        switch ($unit) {
            case 'minute':
                return $value === 1 ? '* * * * *' : "*/{$value} * * * *";
            case 'hour':
                return $value === 1 ? '0 * * * *' : "0 */{$value} * * *";
            case 'day':
                return $value === 1 ? '0 3 * * *' : "0 3 */{$value} * *";
            default:
                return '*/15 * * * *';
        }
    }

    /**
     * Apply current cadence_value / cadence_unit to cron_expression.
     * Called on save so the field never drifts from the input pair.
     */
    public function syncCronExpression(): void
    {
        $this->cron_expression = static::expressionFor(
            (int) $this->cadence_value,
            (string) $this->cadence_unit
        );
    }

    /**
     * The job's options as artisan arguments, one shape for the scheduler
     * (App\Console\Kernel) and for a run by hand (AutomationsController::run).
     * A true value is a bare flag; anything else is --key=value.
     */
    public function commandArguments(): array
    {
        $args = [];
        foreach ((is_array($this->options) ? $this->options : []) as $key => $value) {
            if ($value === null || $value === '' || $value === false) {
                continue;
            }
            $flag = '--' . str_replace('_', '-', (string) $key);
            if ($value === true) {
                $args[$flag] = true;
            } else {
                $args[$flag] = (string) $value;
            }
        }

        return $args;
    }

    public function getOption(string $key, $default = null)
    {
        $opts = $this->options ?? [];
        return $opts[$key] ?? $default;
    }
}
