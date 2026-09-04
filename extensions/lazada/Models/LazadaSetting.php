<?php

namespace Extensions\lazada\Models;

use Illuminate\Database\Eloquent\Model;

class LazadaSetting extends Model
{
    protected $table = 'lazada_settings';

    protected $fillable = [
        'store_name',
        'enabled',
        'mode',
        'region',
        'app_key',
        'app_secret',
        'redirect_uri',
        'auth_code',
        'access_token',
        'refresh_token',
        'expires_at',
        'refresh_expires_at',
        'account',
        'country',
        'sync_last_days',
        'sync_last_days_returns',
        'api_log_mode',
        'last_order_sync_at',
        'last_stock_push_at',
        'last_return_sync_at',
        'last_review_sync_at',
        'sandbox_app_key',
        'sandbox_app_secret',
        'sandbox_redirect_uri',
        'sandbox_auth_code',
        'sandbox_access_token',
        'sandbox_refresh_token',
        'sandbox_expires_at',
        'sandbox_refresh_expires_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'expires_at' => 'datetime',
        'refresh_expires_at' => 'datetime',
        'sandbox_expires_at' => 'datetime',
        'sandbox_refresh_expires_at' => 'datetime',
        'sync_last_days' => 'integer',
        'sync_last_days_returns' => 'integer',
        'last_order_sync_at' => 'datetime',
        'last_stock_push_at' => 'datetime',
        'last_return_sync_at' => 'datetime',
        'last_review_sync_at' => 'datetime',
    ];

    /**
     * Return a read-only decrypted copy of this setting.
     */
    public function decrypted(): object
    {
        $s = (object) $this->toArray();

        foreach (['app_secret', 'auth_code', 'access_token', 'refresh_token', 'sandbox_app_secret', 'sandbox_auth_code', 'sandbox_access_token', 'sandbox_refresh_token'] as $k) {
            if (!empty($s->$k)) {
                try {
                    $s->$k = decrypt($s->$k);
                } catch (\Throwable $e) {
                    // Leave as-is if decrypt fails
                }
            }
        }

        return $s;
    }

    /**
     * Pick the credentials Lazada API calls should use right now, based on
     * the mode toggle. Every call site reads through this — if mode='sandbox'
     * it gets sandbox_* values, if mode='live' it gets the live values.
     *
     * Always returns the full key set (mode, app_key, app_secret, access_token,
     * refresh_token, expires_at, complete). Missing values are returned as null
     * so call sites can safely do `!$creds['access_token']` without worrying
     * about array-access errors. Check `$creds['complete']` to know whether
     * the active mode has the minimum (app_key + app_secret + access_token).
     *
     * Falling back to the other mode's credentials is explicitly NOT done —
     * that would defeat mode isolation. If sandbox is selected but sandbox
     * credentials are missing, the call site sees nulls and refuses to call.
     *
     * Expects $this to be the decrypted() result. Calling on an encrypted
     * row returns ciphertext, which Lazada will reject as InvalidSignature.
     */
    public static function activeCredentials(?object $setting): array
    {
        if (!$setting) {
            return [
                'mode'          => 'live',
                'region'        => null,
                'app_key'       => null,
                'app_secret'    => null,
                'access_token'  => null,
                'refresh_token' => null,
                'expires_at'    => null,
                'complete'      => false,
            ];
        }

        $isSandbox = (($setting->mode ?? 'live') === 'sandbox');

        $appKey       = $isSandbox ? ($setting->sandbox_app_key       ?? null) : ($setting->app_key       ?? null);
        $appSecret    = $isSandbox ? ($setting->sandbox_app_secret    ?? null) : ($setting->app_secret    ?? null);
        $accessToken  = $isSandbox ? ($setting->sandbox_access_token  ?? null) : ($setting->access_token  ?? null);
        $refreshToken = $isSandbox ? ($setting->sandbox_refresh_token ?? null) : ($setting->refresh_token ?? null);
        $expiresAt    = $isSandbox ? ($setting->sandbox_expires_at    ?? null) : ($setting->expires_at    ?? null);

        // Region is a single shared column on lazada_settings (no
        // sandbox_region) — Lazada uses the same regional API hosts
        // for sandbox and production within a given country.
        $region = $setting->region ?? null;

        return [
            'mode'          => $isSandbox ? 'sandbox' : 'live',
            'region'        => $region ? (string) $region : null,
            'app_key'       => $appKey       ? (string) $appKey       : null,
            'app_secret'    => $appSecret    ? (string) $appSecret    : null,
            'access_token'  => $accessToken  ? (string) $accessToken  : null,
            'refresh_token' => $refreshToken ? (string) $refreshToken : null,
            'expires_at'    => $expiresAt,
            'complete'      => !empty($appKey) && !empty($appSecret) && !empty($accessToken),
        ];
    }
}
