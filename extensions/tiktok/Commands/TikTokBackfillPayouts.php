<?php

namespace Extensions\tiktok\Commands;

use Extensions\tiktok\Models\TikTokSetting;
use Extensions\tiktok\Services\TikTok\TikTokPayoutSync;
use Extensions\tiktok\Services\TikTok\TikTokReturnSync;
use Illuminate\Console\Command;

class TikTokBackfillPayouts extends Command
{
    protected $signature = 'tiktok:backfill-payouts
        {--store= : Only this TikTok store, by its id}';

    protected $description = 'Fill in which TikTok Shop orders have been paid out, from every statement since the oldest one waiting';

    public function handle(TikTokPayoutSync $payouts): int
    {
        $stores = TikTokSetting::enabledStores();
        if ($this->option('store') !== null) {
            $stores = $stores->where('id', (int) $this->option('store'))->values();
        }
        if ($stores->isEmpty()) {
            $this->error('No enabled TikTok store' . ($this->option('store') !== null ? ' with that id' : '') . '.');

            return self::FAILURE;
        }

        $exit = self::SUCCESS;
        foreach ($stores as $store) {
            $label = $store->store_name ?: ('#' . $store->id);
            $this->info("=== TikTok store: {$label} ===");

            $creds = TikTokReturnSync::credsFrom($store);
            $expiresAt = $store->mode === 'sandbox' ? $store->sandbox_expires_at : $store->expires_at;
            if (! $creds || ($expiresAt && $expiresAt->isPast())) {
                $this->error('  Not connected, or its access token has expired. Refresh it first.');
                $exit = self::FAILURE;

                continue;
            }

            app()->instance('tiktok.route-store', $store);
            try {
                $result = $payouts->sync($store, $creds, null);
            } finally {
                app()->forgetInstance('tiktok.route-store');
            }

            if ($result['ok']) {
                $this->info('  ' . $result['message']);
            } else {
                $this->error('  ' . $result['message']);
                $exit = self::FAILURE;
            }
        }

        return $exit;
    }
}
