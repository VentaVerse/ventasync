<?php

namespace Extensions\venta\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VentaSyncLog extends Model
{
    protected $table = 'venta_sync_logs';

    protected $fillable = [
        'venta_setting_id',
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

    public function setting(): BelongsTo
    {
        return $this->belongsTo(VentaSetting::class, 'venta_setting_id');
    }
}
