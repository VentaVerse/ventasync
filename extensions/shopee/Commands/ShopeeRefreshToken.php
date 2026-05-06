<?php

namespace Extensions\shopee\Commands;

use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\Services\Shopee\ShopeeClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ShopeeRefreshToken extends Command
{
    protected $signature = 'shopee:refresh-token';

    protected $description = 'Refresh the Shopee access token before it expires';

    public function handle(ShopeeClient $client): int
    {
        $setting = ShopeeSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->partner_id || !$setting->partner_key || !$setting->shop_id || !$setting->refresh_token) {
            $this->error('Missing Shopee credentials. Partner ID, Partner Key, Shop ID, and Refresh Token are required.');
            return 1;
        }

        // Skip if token still has >30 minutes remaining
        if (!empty($setting->expires_at) && now()->diffInMinutes($setting->expires_at, false) > 30) {
            $this->info('Token still valid (expires ' . $setting->expires_at . '). Skipping refresh.');
            return 0;
        }

        $path = '/api/v2/auth/access_token/get';
        $timestamp = time();
        $sign = $client->signAuth((int) $setting->partner_id, (string) $setting->partner_key, $path, $timestamp);

        $query = [
            'partner_id' => (int) $setting->partner_id,
            'timestamp'  => $timestamp,
            'sign'       => $sign,
        ];

        $body = [
            'refresh_token' => (string) $setting->refresh_token,
            'shop_id'       => (int) $setting->shop_id,
            'partner_id'    => (int) $setting->partner_id,
        ];

        $result = $client->postJson($setting->mode ?? 'live', $path, $query, $body);

        if ($result['ok'] && is_array($result['body'])) {
            $access = $result['body']['access_token'] ?? null;
            $refresh = $result['body']['refresh_token'] ?? null;

            if ($access) {
                $raw = ShopeeSetting::query()->first();
                if ($raw) {
                    $raw->access_token = encrypt($access);
                    if ($refresh) {
                        $raw->refresh_token = encrypt($refresh);
                    }
                    $expiresIn = $result['body']['expire_in'] ?? null;
                    if (is_numeric($expiresIn)) {
                        $raw->expires_at = now()->addSeconds((int) $expiresIn);
                    }
                    $raw->save();
                }
                Cache::forget('shopee_sync_paused');
                $this->info('Token refreshed successfully.');
                return 0;
            }
        }

        $msg = is_array($result['body'] ?? null)
            ? ($result['body']['message'] ?? json_encode($result['body']))
            : (string) ($result['body'] ?? 'Unknown error');
        Log::warning('Shopee token refresh failed', ['response' => $msg]);
        $this->error('Token refresh failed: ' . $msg);
        return 1;
    }

}
