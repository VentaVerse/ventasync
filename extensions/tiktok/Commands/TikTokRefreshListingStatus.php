<?php

namespace Extensions\tiktok\Commands;

use Extensions\tiktok\Models\TikTokSetting;
use Extensions\tiktok\Services\TikTok\TikTokLiveListing;
use Illuminate\Console\Command;

/**
 * Refresh the listing status mirror: one walk of the shop, written onto
 * every listing row. Scheduled every 30 minutes (Automations) and run
 * by the Refresh button on the listings page.
 */
class TikTokRefreshListingStatus extends Command
{
    protected $signature = 'tiktok:refresh-listing-status';

    protected $description = 'Refresh the mirrored TikTok Shop status (live, deactivated, under review, failed, frozen) of every linked product';

    public function handle(TikTokLiveListing $live): int
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

        $this->info(sprintf(
            'Refreshed %d listing(s): %s.',
            $result['links'],
            implode(', ', array_map(fn ($k, $v) => "{$v} {$k}", array_keys($result['counts']), $result['counts'])) ?: 'nothing on TikTok Shop'
        ));

        return self::SUCCESS;
    }
}
