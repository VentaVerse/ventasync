<?php

namespace Extensions\ventacart\Models;

use Illuminate\Database\Eloquent\Model;

class VentaCartApiLog extends Model
{
    protected $table = 'ventacart_api_logs';

    protected $fillable = [
        'ventacart_setting_id',
        'method',
        'endpoint',
        'status_code',
        'response_time_ms',
        'request_body',
        'response_body',
        'ok',
    ];

    protected $casts = [
        'request_body'  => 'array',
        'response_body' => 'array',
        'ok'            => 'boolean',
    ];

    public static function safeCreate(array $data): void
    {
        try {
            static::create($data);
        } catch (\Throwable $e) {
        }
    }
}
