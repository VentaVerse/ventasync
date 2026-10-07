<?php

namespace Extensions\opencart\Models;

use Illuminate\Database\Eloquent\Model;

class OpenCartSyncLog extends Model
{
    use \App\Models\Concerns\PrunedByLogLevel;

    protected $table = 'opencart_sync_log';

    protected $fillable = [
        'opencart_setting_id', 'entity_type', 'direction',
        'status', 'started_at', 'details', 'completed_at',
        'records_processed', 'records_updated', 'records_failed',
        'error_message',
    ];

    protected $casts = [
        'details' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
