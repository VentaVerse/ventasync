<?php

namespace Extensions\Shopee\Models;

use Illuminate\Database\Eloquent\Model;

class ShopeeStore extends Model
{
    use \App\Plans\CountsAsStore;

    public const REFRESH_TOKEN_DAYS = 30;

    protected $table = 'ch_shopee_stores';

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
            $store = static::defaultStore()?->decrypted();
            $auth = static::activeAuth($store);
            $region = strtolower(trim((string) ($store->region ?? ''))) ?: 'ph';
            $base = !empty($auth['shop_id'])
                ? 'https://shopee.' . $region . '/product/' . (int) $auth['shop_id']
                : null;
            request()->attributes->set('shopee.listing-url-base', $base);
        }

        return $base !== null ? $base . '/' . $itemId : null;
    }

    public static function activeAuth(?object $store): array
    {
        if (!$store) {
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

        $mode = $store->mode ?? 'sandbox';
        $isSandbox = $mode === 'sandbox';

        $partnerId    = $isSandbox ? ($store->sandbox_partner_id    ?? null) : ($store->partner_id    ?? null);
        $partnerKey   = $isSandbox ? ($store->sandbox_partner_key   ?? null) : ($store->partner_key   ?? null);
        $shopId       = $isSandbox ? ($store->sandbox_shop_id       ?? null) : ($store->shop_id       ?? null);
        $accessToken  = $isSandbox ? ($store->sandbox_access_token  ?? null) : ($store->access_token  ?? null);
        $refreshToken = $isSandbox ? ($store->sandbox_refresh_token ?? null) : ($store->refresh_token ?? null);
        $region       = $isSandbox ? ($store->sandbox_region        ?? null) : ($store->region        ?? null);
        $expiresAt    = $isSandbox ? ($store->sandbox_expires_at    ?? null) : ($store->expires_at    ?? null);

        return [
            'mode'          => $mode,
            'store_id'      => isset($store->id) ? (int) $store->id : null,
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
