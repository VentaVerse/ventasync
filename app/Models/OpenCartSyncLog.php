<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpenCartSyncLog extends Model
{
    protected $table = 'opencart_sync_log';

    protected $fillable = [
        'opencart_setting_id', 'entity_type', 'direction',
        'status', 'started_at', 'details', 'completed_at',
    ];

    protected $casts = [
        'details' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
