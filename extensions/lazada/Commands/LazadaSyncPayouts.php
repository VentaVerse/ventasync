<?php

namespace Extensions\lazada\Commands;

use App\Support\PayoutStatus;
use Extensions\lazada\Models\LazadaSetting;
use Extensions\lazada\Services\Lazada\LazadaPayoutSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class LazadaSyncPayouts extends Command
{
    protected $signature = 'lazada:sync-payouts
        {--days= : Look back this many days (1-365); older orders still not Paid are marked No payout found}
        {--store= : Only this Lazada store, by its id}
        {--recheck : Read the orders already Paid again, and take back a Paid Lazada never credited}';

    protected $description = 'Mark which delivered Lazada orders have been paid out';

    public function handle(LazadaPayoutSync $payouts): int
    {
        $days = PayoutStatus::lookbackDays($this->option('days'));
        $stores = LazadaSetting::enabledStores();
        if ($this->option('store') !== null) {
            $stores = $stores->where('id', (int) $this->option('store'))->values();
        }
        if ($stores->isEmpty()) {
            $this->error('No enabled Lazada store' . ($this->option('store') !== null ? ' with that id' : '') . '.');

            return self::FAILURE;
        }
        if ($paused = Cache::get('lazada_sync_paused')) {
            $this->warn('Lazada is paused after a recent API error (' . $paused . '). It retries on its own.');

            return self::FAILURE;
        }

        $exit = self::SUCCESS;
        foreach ($stores as $store) {
            $this->info('=== Lazada store: ' . ($store->store_name ?: '#' . $store->id) . " (look back {$days} days) ===");
            $setting = $store->decrypted();
            if (! empty($setting->expires_at) && strtotime((string) $setting->expires_at) <= time()) {
                $this->warn('  Access token expired (' . $setting->expires_at . '). Refresh it from Lazada Settings.');
                $exit = self::FAILURE;

                continue;
            }
            $result = $payouts->sync($store, $days, null, (bool) $this->option('recheck'));
            $result['ok'] ? $this->info('  ' . $result['message']) : $this->error('  ' . $result['message']);
            if (! $result['ok']) {
                $exit = self::FAILURE;
            }

            if ($channel = app(\App\Services\Payouts\PayoutRegistry::class)->channel('lazada')) {
                $balance = app(\App\Services\Payouts\PayoutBalances::class)->refresh($channel, $store);
                if (! ($balance['ok'] ?? false)) {
                    $this->warn('  Balance not read: ' . ($balance['error'] ?? 'no answer') . '.');
                }
            }
        }

        return $exit;
    }
}
