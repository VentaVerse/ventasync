<?php

namespace Extensions\lazada\Commands;

use Extensions\lazada\Models\LazadaSetting;
use Extensions\lazada\Services\Lazada\LazadaClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class LazadaRefreshToken extends Command
{
    protected $signature = 'lazada:refresh-token';

    protected $description = 'Refresh the Lazada access token before it expires';

    public function handle(LazadaClient $client): int
    {
        $setting = LazadaSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret || !$setting->refresh_token) {
            $this->error('Missing Lazada credentials. Region, App Key, App Secret, and Refresh Token are required.');
            return 1;
        }

        // Skip if token still has >30 minutes remaining
        if (!empty($setting->expires_at) && now()->diffInMinutes($setting->expires_at, false) > 30) {
            $this->info('Token still valid (expires ' . $setting->expires_at . '). Skipping refresh.');
            return 0;
        }

        $apiPath = '/auth/token/refresh';
        $timestamp = (string) round(microtime(true) * 1000);

        $params = [
            'app_key'       => (string) $setting->app_key,
            'sign_method'   => 'sha256',
            'timestamp'     => $timestamp,
            'refresh_token' => (string) $setting->refresh_token,
            'grant_type'    => 'refresh_token',
        ];

        $params['sign'] = $client->sign($apiPath, $params, (string) $setting->app_secret);

        $result = $client->post((string) $setting->region, $apiPath, $params);

        if ($result['ok'] && is_array($result['body'])) {
            $access = $result['body']['access_token'] ?? null;
            $refresh = $result['body']['refresh_token'] ?? null;
            $expiresIn = $result['body']['expires_in'] ?? null;
            $refreshExpiresIn = $result['body']['refresh_expires_in'] ?? null;

            if ($access) {
                $raw = LazadaSetting::query()->first();
                if ($raw) {
                    $raw->access_token = encrypt((string) $access);
                    if ($refresh) {
                        $raw->refresh_token = encrypt((string) $refresh);
                    }
                    if (is_numeric($expiresIn)) {
                        $raw->expires_at = now()->addSeconds((int) $expiresIn);
                    }
                    if (is_numeric($refreshExpiresIn)) {
                        $raw->refresh_expires_at = now()->addSeconds((int) $refreshExpiresIn);
                    }
                    $raw->save();
                }
                $this->info('Token refreshed successfully.');
                return 0;
            }
        }

        $msg = is_array($result['body'] ?? null)
            ? ($result['body']['message'] ?? json_encode($result['body']))
            : (string) ($result['body'] ?? 'Unknown error');
        Log::warning('Lazada token refresh failed', ['response' => $msg]);
        $this->error('Token refresh failed: ' . $msg);
        return 1;
    }
}
