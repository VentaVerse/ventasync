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
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['partner_id'] || !$auth['partner_key'] || !$auth['shop_id'] || !$auth['refresh_token']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            $this->error("Missing Shopee {$modeLabel} credentials. Partner ID, Partner Key, Shop ID, and Refresh Token are required.");
            return 1;
        }

        // Skip if token still has >30 minutes remaining
        if (!empty($auth['expires_at']) && now()->diffInMinutes($auth['expires_at'], false) > 30) {
            $this->info('Token still valid (expires ' . $auth['expires_at'] . '). Skipping refresh.');
            return 0;
        }

        $path = '/api/v2/auth/access_token/get';
        $timestamp = time();
        $sign = $client->signAuth((int) $auth['partner_id'], (string) $auth['partner_key'], $path, $timestamp);

        $query = [
            'partner_id' => (int) $auth['partner_id'],
            'timestamp'  => $timestamp,
            'sign'       => $sign,
        ];

        $body = [
            'refresh_token' => (string) $auth['refresh_token'],
            'shop_id'       => (int) $auth['shop_id'],
            'partner_id'    => (int) $auth['partner_id'],
        ];

        $result = $client->postJson($auth['mode'], $path, $query, $body);

        if ($result['ok'] && is_array($result['body'])) {
            $access = $result['body']['access_token'] ?? null;
            $refresh = $result['body']['refresh_token'] ?? null;

            if ($access) {
                $raw = ShopeeSetting::query()->first();
                if ($raw) {
                    $isSandbox = $auth['mode'] === 'sandbox';
                    if ($isSandbox) {
                        $raw->sandbox_access_token = encrypt($access);
                        if ($refresh) {
                            $raw->sandbox_refresh_token = encrypt($refresh);
                        }
                        $expiresIn = $result['body']['expire_in'] ?? null;
                        if (is_numeric($expiresIn)) {
                            $raw->sandbox_expires_at = now()->addSeconds((int) $expiresIn);
                        }
                    } else {
                        $raw->access_token = encrypt($access);
                        if ($refresh) {
                            $raw->refresh_token = encrypt($refresh);
                        }
                        $expiresIn = $result['body']['expire_in'] ?? null;
                        if (is_numeric($expiresIn)) {
                            $raw->expires_at = now()->addSeconds((int) $expiresIn);
                        }
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
