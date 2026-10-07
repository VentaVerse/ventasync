<?php

namespace Extensions\shopee\Models;

use Illuminate\Database\Eloquent\Model;

class ShopeeSetting extends Model
{
    use \App\Plans\CountsAsStore;

    public const REFRESH_TOKEN_DAYS = 30;

    protected $table = 'shopee_settings';

    protected $fillable = [
        'store_name',
        'enabled',
        'mode',
        'partner_id',
        'partner_key',
        'push_partner_key',
        'apply_order_pushes',
        'shop_id',
        'access_token',
        'refresh_token',
        'expires_at',
        'refresh_expires_at',
        'redirect_uri',
        'region',
        'sync_last_days',
        'sync_last_days_returns',
        'api_log_mode',
        'last_order_sync_at',
        'last_stock_push_at',
        'last_return_sync_at',
        'last_review_sync_at',
        'sandbox_partner_id',
        'sandbox_partner_key',
        'sandbox_push_partner_key',
        'sandbox_shop_id',
        'sandbox_access_token',
        'sandbox_refresh_token',
        'sandbox_expires_at',
        'sandbox_refresh_expires_at',
        'sandbox_redirect_uri',
        'sandbox_region',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'apply_order_pushes' => 'boolean',
        'expires_at' => 'datetime',
        'refresh_expires_at' => 'datetime',
        'sandbox_expires_at' => 'datetime',
        'sandbox_refresh_expires_at' => 'datetime',
        'last_order_sync_at' => 'datetime',
        'last_stock_push_at' => 'datetime',
        'last_return_sync_at' => 'datetime',
        'last_review_sync_at' => 'datetime',
    ];

    public static function defaultStore(): ?self
    {
        if (app()->bound('shopee.route-store')) {
            return app('shopee.route-store');
        }

        return static::query()->where('enabled', true)->orderBy('id')->first();
    }

    public static function syncUrlDefault(): void
    {
        if (app()->bound('shopee.route-store')) {
            return;
        }

        try {
            $id = static::query()->where('enabled', true)->orderBy('id')->value('id');
        } catch (\Throwable $e) {
            return;
        }
        if ($id !== null) {
            \Illuminate\Support\Facades\URL::defaults(['store' => (int) $id]);
        }
    }

    protected static function booted(): void
    {
        static::created(fn () => static::syncUrlDefault());
        static::updated(fn () => static::syncUrlDefault());
        static::deleted(fn () => static::syncUrlDefault());
    }

    public function decrypted(): object
    {
        $s = (object) $this->toArray();

        foreach (['partner_key', 'push_partner_key', 'access_token', 'refresh_token', 'sandbox_partner_key', 'sandbox_push_partner_key', 'sandbox_access_token', 'sandbox_refresh_token'] as $k) {
            if (!empty($s->$k)) {
                try {
                    $s->$k = decrypt($s->$k);
                } catch (\Throwable $e) {
                }
            }
        }

        return $s;
    }

    public static function publicListingUrl(int|string $itemId): ?string
    {
        $base = request()->attributes->get('shopee.listing-url-base', false);
        if ($base === false) {
            $setting = static::defaultStore()?->decrypted();
            $auth = static::activeAuth($setting);
            $region = strtolower(trim((string) ($setting->region ?? ''))) ?: 'ph';
            $base = !empty($auth['shop_id'])
                ? 'https://shopee.' . $region . '/product/' . (int) $auth['shop_id']
                : null;
            request()->attributes->set('shopee.listing-url-base', $base);
        }

        return $base !== null ? $base . '/' . $itemId : null;
    }

    public static function activeAuth(?object $setting): array
    {
        if (!$setting) {
            return [
                'mode'          => 'sandbox',
                'store_id'      => null,
                'partner_id'    => null,
                'partner_key'   => null,
                'shop_id'       => null,
                'access_token'  => null,
                'refresh_token' => null,
                'region'        => null,
                'expires_at'    => null,
                'complete'      => false,
            ];
        }

        $mode = $setting->mode ?? 'sandbox';
        $isSandbox = $mode === 'sandbox';

        $partnerId    = $isSandbox ? ($setting->sandbox_partner_id    ?? null) : ($setting->partner_id    ?? null);
        $partnerKey   = $isSandbox ? ($setting->sandbox_partner_key   ?? null) : ($setting->partner_key   ?? null);
        $shopId       = $isSandbox ? ($setting->sandbox_shop_id       ?? null) : ($setting->shop_id       ?? null);
        $accessToken  = $isSandbox ? ($setting->sandbox_access_token  ?? null) : ($setting->access_token  ?? null);
        $refreshToken = $isSandbox ? ($setting->sandbox_refresh_token ?? null) : ($setting->refresh_token ?? null);
        $region       = $isSandbox ? ($setting->sandbox_region        ?? null) : ($setting->region        ?? null);
        $expiresAt    = $isSandbox ? ($setting->sandbox_expires_at    ?? null) : ($setting->expires_at    ?? null);

        return [
            'mode'          => $mode,
            'store_id'      => isset($setting->id) ? (int) $setting->id : null,
            'partner_id'    => $partnerId,
            'partner_key'   => $partnerKey,
            'shop_id'       => $shopId,
            'access_token'  => $accessToken  ? (string) $accessToken  : null,
            'refresh_token' => $refreshToken ? (string) $refreshToken : null,
            'region'        => $region,
            'expires_at'    => $expiresAt,
            'complete'      => !empty($partnerId) && !empty($partnerKey) && !empty($accessToken) && !empty($shopId),
        ];
    }
}
