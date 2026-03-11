<?php

namespace Extensions\tiktok\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class TikTokApiLog extends Model
{
    protected $table = 'tiktok_api_logs';

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
            $setting = TikTokSetting::query()->first();
            if ($setting && $setting->api_logging === false) {
                return;
            }

            if (array_key_exists('pack', $attributes) && !Schema::hasColumn('tiktok_api_logs', 'pack')) {
                unset($attributes['pack']);
            }

            self::query()->create($attributes);
        } catch (QueryException $e) {
            Log::warning('Failed to write tiktok_api_logs record', [
                'error' => $e->getMessage(),
                'keys' => array_keys($attributes),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Unexpected failure writing tiktok_api_logs record', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
