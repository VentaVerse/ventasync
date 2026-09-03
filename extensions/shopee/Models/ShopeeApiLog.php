<?php

namespace Extensions\shopee\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ShopeeApiLog extends Model
{
    use \Extensions\shopee\Models\Concerns\BelongsToShopeeStore;

    protected $table = 'shopee_api_logs';

    protected $fillable = [
        'shopee_setting_id',
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

    /**
     * Create an API log record without breaking the app if schema is out of sync.
     */
    public static function safeCreate(array $attributes): void
    {
        try {
            // The three-way mode (#200): off, errors only, everything.
            $setting = ShopeeSetting::defaultStore();
            if (! \App\Support\ApiLogMode::shouldLog($setting?->api_log_mode, (bool) ($attributes['ok'] ?? true))) {
                return;
            }

            if (array_key_exists('pack', $attributes) && !Schema::hasColumn('shopee_api_logs', 'pack')) {
                unset($attributes['pack']);
            }

            self::query()->create($attributes);
        } catch (QueryException $e) {
            Log::warning('Failed to write shopee_api_logs record', [
                'error' => $e->getMessage(),
                'keys' => array_keys($attributes),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Unexpected failure writing shopee_api_logs record', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
