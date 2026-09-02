<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One delivery in the webhook inbox (Phase E, #184). Stored by the public
 * endpoint, applied by webhooks:process. An event that failed signature
 * verification stays here unprocessed - evidence, never authority.
 */
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
