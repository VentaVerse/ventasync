<?php

namespace Extensions\lazada\Models;

use Illuminate\Database\Eloquent\Model;

class LazadaSetting extends Model
{
    protected $table = 'lazada_settings';

    protected $fillable = [
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
        'api_logging',
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
        'expires_at' => 'datetime',
        'refresh_expires_at' => 'datetime',
        'sandbox_expires_at' => 'datetime',
        'sandbox_refresh_expires_at' => 'datetime',
        'sync_last_days' => 'integer',
        'sync_last_days_returns' => 'integer',
        'api_logging' => 'boolean',
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
     * Returns null when the active mode has missing required credentials
     * (app_key/app_secret/access_token). Call sites must check for null and
     * abort instead of falling back to the other mode — that would defeat
     * the whole point of mode isolation.
     *
     * Expects $this to be the decrypted() result. Calling on an encrypted
     * row returns ciphertext, which Lazada will reject as InvalidSignature.
     */
    public function activeCredentials(): ?array
    {
        $isSandbox = (($this->mode ?? 'live') === 'sandbox');

        $appKey       = $isSandbox ? ($this->sandbox_app_key       ?? null) : ($this->app_key       ?? null);
        $appSecret    = $isSandbox ? ($this->sandbox_app_secret    ?? null) : ($this->app_secret    ?? null);
        $accessToken  = $isSandbox ? ($this->sandbox_access_token  ?? null) : ($this->access_token  ?? null);
        $refreshToken = $isSandbox ? ($this->sandbox_refresh_token ?? null) : ($this->refresh_token ?? null);
        $expiresAt    = $isSandbox ? ($this->sandbox_expires_at    ?? null) : ($this->expires_at    ?? null);

        if (empty($appKey) || empty($appSecret) || empty($accessToken)) {
            return null;
        }

        return [
            'mode'          => $isSandbox ? 'sandbox' : 'live',
            'app_key'       => (string) $appKey,
            'app_secret'    => (string) $appSecret,
            'access_token'  => (string) $accessToken,
            'refresh_token' => $refreshToken ? (string) $refreshToken : null,
            'expires_at'    => $expiresAt,
        ];
    }
}
