<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutomationRun extends Model
{
    public const RUNNING = 'running';
    public const STOPPED = 'stopped';
    public const FAILED = 'failed';
    public const DONE = 'done';

    protected $guarded = [];

    protected $casts = [
        'units' => 'array',
        'ledger' => 'array',
    ];

    public function job()
    {
        return $this->belongsTo(ScheduledJob::class, 'scheduled_job_id');
    }

    public function toState(): array
    {
        return [
            'id' => (int) $this->id,
            'job_id' => (int) $this->scheduled_job_id,
            'status' => (string) $this->status,
            'label' => (string) $this->label,
            'total' => (int) $this->total,
            'done' => (int) $this->done,
            'ok' => (int) $this->ok,
            'failed' => (int) $this->failed,
            'last_error' => $this->last_error,
            'ledger' => array_values((array) ($this->ledger ?? [])),
        ];
    }
}
