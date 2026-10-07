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
        $stores = \Extensions\lazada\Models\LazadaSetting::enabledStores();
        if ($stores->isEmpty()) { $this->error('No enabled Lazada store. Configure Lazada settings first.'); return 1; }
        $worst = 0;
        foreach ($stores as $store) {
            $label = $store->store_name ?: ('#' . $store->id);
            $this->info("=== Lazada store: {$label} ===");
            app()->instance('lazada.route-store', $store);
            try {
                $worst = max($worst, $this->handleStore($client, $store));
            } catch (\Throwable $e) {
                $this->error("Store {$label} failed: " . $e->getMessage());
                \Illuminate\Support\Facades\Log::error('Lazada '.class_basename($this).': store failed', ['store' => $store->id, 'error' => $e->getMessage()]);
                $worst = 1;
            }
        }
        app()->forgetInstance('lazada.route-store');
        return $worst;
    }

    private function handleStore(LazadaClient $client, \Extensions\lazada\Models\LazadaSetting $store): int
    {
        $setting = $store->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        $isSandbox = (($setting->mode ?? 'live') === 'sandbox');
        $modeLabel = $isSandbox ? 'sandbox' : 'live';

        if (!$setting || empty($creds['region']) || !$creds['complete'] || empty($creds['refresh_token'])) {
            $this->error("Missing Lazada {$modeLabel} credentials. Region, app_key, app_secret, and refresh_token are required for the active mode.");
            return 1;
        }

        $expiresAt = $creds['expires_at'] ?? null;
        if (!empty($expiresAt) && now()->diffInMinutes($expiresAt, false) > 1440) {
            $this->info("{$modeLabel} token still valid (expires {$expiresAt}). Skipping refresh.");
            return 0;
        }

        $apiPath = '/auth/token/refresh';
        $timestamp = (string) round(microtime(true) * 1000);

        $params = [
            'app_key'       => $creds['app_key'],
            'sign_method'   => 'sha256',
            'timestamp'     => $timestamp,
            'refresh_token' => $creds['refresh_token'],
            'grant_type'    => 'refresh_token',
        ];

        $params['sign'] = $client->sign($apiPath, $params, $creds['app_secret']);

        $result = $client->post((string) $creds['region'], $apiPath, $params);

        if ($result['ok'] && is_array($result['body'])) {
            $access = $result['body']['access_token'] ?? null;
            $refresh = $result['body']['refresh_token'] ?? null;
            $expiresIn = $result['body']['expires_in'] ?? null;
            $refreshExpiresIn = $result['body']['refresh_expires_in'] ?? null;

            if ($access) {
                $raw = LazadaSetting::query()->find($store->id);
                if ($raw) {
                    if ($isSandbox) {
                        $raw->sandbox_access_token = encrypt((string) $access);
                        if ($refresh) {
                            $raw->sandbox_refresh_token = encrypt((string) $refresh);
                        }
                        if (is_numeric($expiresIn)) {
                            $raw->sandbox_expires_at = now()->addSeconds((int) $expiresIn);
                        }
                        if (is_numeric($refreshExpiresIn)) {
                            $raw->sandbox_refresh_expires_at = now()->addSeconds((int) $refreshExpiresIn);
                        }
                    } else {
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
                    }
                    $raw->save();
                }
                $this->info(ucfirst($modeLabel) . ' token refreshed successfully.');
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
