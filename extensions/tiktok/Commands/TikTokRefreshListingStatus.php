<?php

namespace Extensions\tiktok\Commands;

use Extensions\tiktok\Models\TikTokSetting;
use Extensions\tiktok\Services\TikTok\TikTokLiveListing;
use Illuminate\Console\Command;

class TikTokRefreshListingStatus extends Command
{
    protected $signature = 'tiktok:refresh-listing-status';

    protected $description = 'Refresh the mirrored TikTok Shop status (live, deactivated, under review, failed, frozen) of every linked product';

    public function handle(TikTokLiveListing $live): int
    {
        $stores = \Extensions\tiktok\Models\TikTokSetting::enabledStores();
        if ($stores->isEmpty()) { $this->error('No enabled TikTok store. Configure TikTok settings first.'); return 1; }
        $worst = 0;
        foreach ($stores as $store) {
            $label = $store->store_name ?: ('#' . $store->id);
            $this->info("=== TikTok store: {$label} ===");
            app()->instance('tiktok.route-store', $store);
            try {
                $worst = max($worst, $this->handleStore($live, $store));
            } catch (\Throwable $e) {
                $this->error("Store {$label} failed: " . $e->getMessage());
                \Illuminate\Support\Facades\Log::error('TikTok '.class_basename($this).': store failed', ['store' => $store->id, 'error' => $e->getMessage()]);
                $worst = 1;
            }
        }
        app()->forgetInstance('tiktok.route-store');
        return $worst;
    }

    private function handleStore(TikTokLiveListing $live, \Extensions\tiktok\Models\TikTokSetting $store): int
    {
        $c = TikTokSetting::credentials();
        if (!$c) {
            $this->error('Missing TikTok credentials. Configure TikTok settings first.');
            return self::FAILURE;
        }

        $result = $live->refreshMirror($c);
        if ($result['error'] !== null) {
            $this->error($result['error']);
            return self::FAILURE;
        }


        $checked = app(\Extensions\tiktok\Services\TikTok\TikTokLinkCheck::class)->runNext($c, (int) $store->id);
        $sheets = app(\Extensions\tiktok\Services\TikTok\TikTokSheetReads::class)->readMissing($c, app(\Extensions\tiktok\Services\TikTok\TikTokClient::class));

        $this->info(sprintf(
            'Refreshed %d listing(s): %s.%s%s',
            $result['links'],
            implode(', ', array_map(fn ($k, $v) => "{$v} {$k}", array_keys($result['counts']), $result['counts'])) ?: 'nothing on TikTok Shop',
            $checked ? ' ' . $checked['summary'] : '',
            $sheets['summary'] !== '' ? ' ' . $sheets['summary'] : ''
        ));

        return self::SUCCESS;
    }
}
