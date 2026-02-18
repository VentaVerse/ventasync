<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpenCartSyncLog extends Model
{
    protected $table = 'opencart_sync_log';

    protected $guarded = [];

    protected $casts = [
        'details' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
