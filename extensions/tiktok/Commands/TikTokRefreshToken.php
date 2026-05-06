<?php

namespace Extensions\tiktok\Commands;

use Extensions\tiktok\Models\TikTokSetting;
use Extensions\tiktok\Services\TikTok\TikTokClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TikTokRefreshToken extends Command
{
    protected $signature = 'tiktok:refresh-token';

    protected $description = 'Refresh the TikTok Shop access token before it expires';

    public function handle(): int
    {
        $raw = TikTokSetting::query()->first();
        if (!$raw) {
            $this->error('TikTok settings not configured.');
            return 1;
        }

        $s = $raw->decrypted();
        $sandbox = $raw->mode === 'sandbox';

        $appKey       = $sandbox ? ($s->sandbox_app_key ?? '') : ($s->app_key ?? '');
        $appSecret    = $sandbox ? ($s->sandbox_app_secret ?? '') : ($s->app_secret ?? '');
        $refreshToken = $sandbox ? ($s->sandbox_refresh_token ?? '') : ($s->refresh_token ?? '');
        $expiresAt    = $sandbox ? $raw->sandbox_expires_at : $raw->expires_at;

        if (!$appKey || !$appSecret || !$refreshToken) {
            $this->error('Missing TikTok credentials. App Key, App Secret, and Refresh Token are required.');
            return 1;
        }

        // Skip if token still has >30 minutes remaining
        if ($expiresAt && now()->diffInMinutes($expiresAt, false) > 30) {
            $this->info('Token still valid (expires ' . $expiresAt . '). Skipping refresh.');
            return 0;
        }

        $client = new TikTokClient();
        $result = $client->refreshToken($appKey, $appSecret, $refreshToken);

        if ($result['ok'] && is_array($result['body'])) {
            $data = $result['body']['data'] ?? $result['body'];
            $access = $data['access_token'] ?? null;
            $refresh = $data['refresh_token'] ?? null;
            $expiresIn = $data['access_token_expire_in'] ?? ($data['expires_in'] ?? null);
            $refreshExpiresIn = $data['refresh_token_expire_in'] ?? ($data['refresh_expires_in'] ?? null);

            if ($access) {
                $prefix = $sandbox ? 'sandbox_' : '';
                $raw->{$prefix . 'access_token'} = encrypt((string) $access);
                if ($refresh) {
                    $raw->{$prefix . 'refresh_token'} = encrypt((string) $refresh);
                }
                if (is_numeric($expiresIn)) {
                    // TikTok returns Unix timestamps, not seconds-from-now
                    $raw->{$prefix . 'expires_at'} = \Carbon\Carbon::createFromTimestamp((int) $expiresIn);
                }
                if (is_numeric($refreshExpiresIn)) {
                    $raw->{$prefix . 'refresh_expires_at'} = \Carbon\Carbon::createFromTimestamp((int) $refreshExpiresIn);
                }
                $raw->save();

                $this->info('TikTok token refreshed successfully.');
                return 0;
            }
        }

        $msg = is_array($result['body'] ?? null)
            ? ($result['body']['message'] ?? json_encode($result['body']))
            : (string) ($result['body'] ?? 'Unknown error');
        Log::warning('TikTok token refresh failed', ['response' => $msg]);
        $this->error('Token refresh failed: ' . $msg);
        return 1;
    }
}
