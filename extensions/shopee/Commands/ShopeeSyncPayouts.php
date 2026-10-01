<?php

namespace Extensions\shopee\Commands;

use App\Support\PayoutStatus;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\Services\Shopee\ShopeePayoutSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * The Sync Payouts automation for Shopee (the owner, 2026-10-01): which completed
 * orders Shopee has released to the wallet. Its own job, hourly, so the
 * every-minute order sync no longer re-reads the wallet. --days is the job's
 * look-back; see ShopeePayoutSync.
 */
class ShopeeSyncPayouts extends Command
{
    protected $signature = 'shopee:sync-payouts
        {--days= : Look back this many days (1-365); older orders still not Paid are marked No payout found}
        {--store= : Only this Shopee store, by its id}';

    protected $description = 'Mark which completed Shopee orders have been paid out to the wallet';

    public function handle(ShopeePayoutSync $payouts): int
    {
        $days = PayoutStatus::lookbackDays($this->option('days'));
        $stores = ShopeeSetting::query()->where('enabled', true)
            ->when($this->option('store') !== null, fn ($q) => $q->whereKey((int) $this->option('store')))
            ->orderBy('id')->get();
        if ($stores->isEmpty()) {
            $this->error('No enabled Shopee store' . ($this->option('store') !== null ? ' with that id' : '') . '.');

            return self::FAILURE;
        }

        $exit = self::SUCCESS;
        foreach ($stores as $store) {
            $this->info('=== Shopee store: ' . ($store->store_name ?: '#' . $store->id) . " (look back {$days} days) ===");
            if ($paused = Cache::get('shopee_sync_paused:' . $store->id)) {
                $this->warn('  Paused after a recent Shopee error (' . $paused . '). It retries on its own.');
                $exit = self::FAILURE;

                continue;
            }
            $result = $payouts->sync($store, $days);
            $result['ok'] ? $this->info('  ' . $result['message']) : $this->error('  ' . $result['message']);
            if (! $result['ok']) {
                $exit = self::FAILURE;
            }
        }

        return $exit;
    }
}
