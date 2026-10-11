<?php

namespace Extensions\VentaCart\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VentaCartSyncLog extends Model
{
    use \App\Models\Concerns\PrunedByLogLevel;

    protected $table = 'ch_ventacart_sync_logs';

    protected $fillable = [
        'store_id',
        'entity_type',
        'direction',
        'status',
        'started_at',
        'completed_at',
        'records_processed',
        'records_created',
        'records_updated',
        'records_skipped',
        'records_failed',
        'error_message',
        'details',
    ];

    protected $casts = [
        'details'      => 'array',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(VentaCartStore::class, 'store_id');
    }

    public function syncLogLevel(): ?string
    {
        $id = (int) ($this->store_id ?? 0);
        if ($id <= 0) {
            return null;
        }

        return \Illuminate\Support\Facades\DB::table('ch_ventacart_stores')->where('id', $id)->value('sync_log_level');
    }
}
