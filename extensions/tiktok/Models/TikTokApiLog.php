<?php

namespace Extensions\TikTok\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class TikTokApiLog extends Model
{
    protected $table = 'ch_tiktok_api_logs';

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
            $attributes['ok'] = \App\Support\MarketplaceVerdict::ok('tiktok', (bool) ($attributes['ok'] ?? true), $attributes['response_body'] ?? null);
            $store = TikTokStore::defaultStore();
            if (! \App\Support\ApiLogMode::shouldLog($store?->api_log_mode, (bool) ($attributes['ok'] ?? true))) {
                return;
            }

            if (array_key_exists('pack', $attributes) && !Schema::hasColumn('ch_tiktok_api_logs', 'pack')) {
                unset($attributes['pack']);
            }

            self::query()->create($attributes);
        } catch (QueryException $e) {
            Log::warning('Failed to write ch_tiktok_api_logs record', [
                'error' => $e->getMessage(),
                'keys' => array_keys($attributes),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Unexpected failure writing ch_tiktok_api_logs record', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
