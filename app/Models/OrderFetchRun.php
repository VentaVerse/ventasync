<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderFetchRun extends Model
{
    public const RUNNING = 'running';
    public const STOPPED = 'stopped';
    public const FAILED = 'failed';
    public const DONE = 'done';

    protected $guarded = [];

    protected $casts = [
        'options' => 'array',
        'cursor' => 'array',
        'ledger' => 'array',
        'date_from' => 'date',
        'date_to' => 'date',
    ];

    public static function resumable(string $integration, ?int $storeId): ?self
    {
        return static::query()
            ->where('integration', $integration)
            ->where(fn ($q) => $storeId === null ? $q->whereNull('store_id') : $q->where('store_id', $storeId))
            ->whereIn('status', [self::STOPPED, self::FAILED, self::RUNNING])
            ->orderByDesc('id')
            ->first();
    }

    public function isFinished(): bool
    {
        return $this->status === self::DONE;
    }

    public function toState(): array
    {
        return [
            'id' => (int) $this->id,
            'status' => (string) $this->status,
            'date_from' => $this->date_from?->format('Y-m-d'),
            'date_to' => $this->date_to?->format('Y-m-d'),
            'page' => (int) $this->page,
            'pages' => $this->pages !== null ? (int) $this->pages : null,
            'total' => $this->total !== null ? (int) $this->total : null,
            'read' => (int) $this->read,
            'created' => (int) $this->created,
            'updated' => (int) $this->updated,
            'failed' => (int) $this->failed,
            'last_error' => $this->last_error,
            'ledger' => array_values((array) ($this->ledger ?? [])),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
