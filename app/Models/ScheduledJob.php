<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    public function syncCronExpression(): void
    {
        $this->cron_expression = static::expressionFor(
            (int) $this->cadence_value,
            (string) $this->cadence_unit
        );
    }

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
