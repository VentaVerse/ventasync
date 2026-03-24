<?php

namespace Extensions\venta\Models;

use Illuminate\Database\Eloquent\Model;

class VentaApiLog extends Model
{
    protected $table = 'venta_api_logs';

    protected $fillable = [
        'venta_setting_id',
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
            // Never let logging break the main flow
        }
    }
}
