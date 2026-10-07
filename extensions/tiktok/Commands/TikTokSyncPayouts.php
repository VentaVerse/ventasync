<?php

namespace Extensions\tiktok\Commands;

use App\Support\PayoutStatus;
use Extensions\tiktok\Models\TikTokOrder;
use Extensions\tiktok\Models\TikTokSetting;
use Extensions\tiktok\Services\TikTok\TikTokPayoutSync;
use Extensions\tiktok\Services\TikTok\TikTokReturnSync;
use Illuminate\Console\Command;

class TikTokSyncPayouts extends Command
{
    protected $signature = 'tiktok:sync-payouts
        {--days= : Look back this many days (1-365); older orders still not Paid are marked No payout found}
        {--store= : Only this TikTok store, by its id}';

    protected $description = 'Mark which delivered TikTok Shop orders have been paid out';

    public function handle(TikTokPayoutSync $payouts): int
    {
        $days = PayoutStatus::lookbackDays($this->option('days'));
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
            $this->info('=== TikTok store: ' . ($store->store_name ?: '#' . $store->id) . " (look back {$days} days) ===");
            $creds = TikTokReturnSync::credsFrom($store);
            $expiresAt = $store->mode === 'sandbox' ? $store->sandbox_expires_at : $store->expires_at;
            if (! $creds || ($expiresAt && $expiresAt->isPast())) {
                $this->error('  Not connected, or its access token has expired. Refresh it first.');
                $exit = self::FAILURE;

                continue;
            }

            $retired = PayoutStatus::retireOlderThan(
                TikTokOrder::query()->where('tiktok_setting_id', $store->id)->whereIn('status', TikTokPayoutSync::PAYABLE_STATUSES),
                $days
            );
            if ($retired) {
                $this->info("  {$retired} older than {$days} days marked No payout found.");
            }

            app()->instance('tiktok.route-store', $store);
            try {
                $result = $payouts->sync($store, $creds, now()->subDays($days)->getTimestamp());
            } finally {
                app()->forgetInstance('tiktok.route-store');
            }
            $result['ok'] ? $this->info('  ' . $result['message']) : $this->error('  ' . $result['message']);
            if (! $result['ok']) {
                $exit = self::FAILURE;
            }

            if ($channel = app(\App\Services\Payouts\PayoutRegistry::class)->channel('tiktok')) {
                $balance = app(\App\Services\Payouts\PayoutBalances::class)->refresh($channel, $store);
                if (! ($balance['ok'] ?? false)) {
                    $this->warn('  Balance not read: ' . ($balance['error'] ?? 'no answer') . '.');
                }
            }
        }

        return $exit;
    }
}
