<?php

namespace Extensions\lazada\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class LazadaApiLog extends Model
{
    protected $table = 'lazada_api_logs';

    protected $fillable = [
        'pack',
        'method',
        'api_path',
        'auth_required',
        'request_params',
        'response_status',
        'ok',
        'response_body',
        'user_id',
    ];

    protected $casts = [
        'auth_required' => 'boolean',
        'ok' => 'boolean',
        'request_params' => 'array',
        'response_body' => 'array',
    ];

    public static function safeCreate(array $attributes): void
    {
        try {
            $attributes['ok'] = \App\Support\MarketplaceVerdict::ok('lazada', (bool) ($attributes['ok'] ?? true), $attributes['response_body'] ?? null);
            $setting = LazadaSetting::defaultStore();
            if (! \App\Support\ApiLogMode::shouldLog($setting?->api_log_mode, (bool) ($attributes['ok'] ?? true))) {
                return;
            }

            if (array_key_exists('pack', $attributes) && !Schema::hasColumn('lazada_api_logs', 'pack')) {
                unset($attributes['pack']);
            }

            self::query()->create($attributes);
        } catch (QueryException $e) {
            Log::warning('Failed to write lazada_api_logs record', [
                'error' => $e->getMessage(),
                'keys' => array_keys($attributes),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Unexpected failure writing lazada_api_logs record', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
