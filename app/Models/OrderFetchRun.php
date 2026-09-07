<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One fetch of orders from one store: a window of dates, a place in the
 * store's pages, and the counts so far. Driven one page per request by
 * the orders page (App\Services\OrderFetchRunner), so no request is ever
 * long enough to hit the server's limit, and a run that stopped for any
 * reason continues from its place.
 */
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

    /** The run the page can continue: the latest one for this store that did not finish. */
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

    /** What the page draws: numbers, the last sentences, the words for the strip. */
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
