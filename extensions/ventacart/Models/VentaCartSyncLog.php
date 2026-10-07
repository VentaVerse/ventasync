<?php

namespace Extensions\ventacart\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VentaCartSyncLog extends Model
{
    use \App\Models\Concerns\PrunedByLogLevel;

    protected $table = 'ventacart_sync_logs';

    protected $fillable = [
        'ventacart_setting_id',
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
        return $this->belongsTo(VentaCartSetting::class, 'ventacart_setting_id');
    }

    public function syncLogLevel(): ?string
    {
        $id = (int) ($this->ventacart_setting_id ?? 0);
        if ($id <= 0) {
            return null;
        }

        return \Illuminate\Support\Facades\DB::table('ventacart_settings')->where('id', $id)->value('sync_log_level');
    }
}
