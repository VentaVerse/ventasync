<?php

namespace Extensions\shopee\Commands;

use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\Services\Shopee\ShopeeLiveListing;
use Illuminate\Console\Command;

/**
 * Refresh the listing status mirror: what Shopee holds in each bucket,
 * written onto every link row. Scheduled every 30 minutes (Automations)
 * and run by the Refresh button on the listings page.
 */
class ShopeeRefreshListingStatus extends Command
{
    protected $signature = 'shopee:refresh-listing-status';

    protected $description = 'Refresh the mirrored Shopee status (live, unlisted, violation, under review) of every linked product';

    public function handle(ShopeeLiveListing $live): int
    {
        $setting = ShopeeSetting::query()->first()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $this->error('Missing Shopee credentials. Configure Shopee settings first.');
            return self::FAILURE;
        }

        $result = $live->refreshMirror($auth);
        if ($result['error'] !== null) {
            $this->error('Shopee did not answer: ' . $result['error']);
            return self::FAILURE;
        }

        $this->info(sprintf(
            'Refreshed %d link(s): %s.',
            $result['links'],
            implode(', ', array_map(fn ($k, $v) => "{$v} {$k}", array_keys($result['counts']), $result['counts'])) ?: 'nothing on Shopee'
        ));

        return self::SUCCESS;
    }
}
