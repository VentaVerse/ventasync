<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChannelWebhookEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'channel', 'event_type', 'payload', 'signature', 'signature_ok',
        'received_at', 'processed_at', 'outcome',
    ];

    protected $casts = [
        'payload' => 'array',
        'signature_ok' => 'boolean',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
