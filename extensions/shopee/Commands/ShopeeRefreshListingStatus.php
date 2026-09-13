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

    /** Multi-store S3 (#194): one mirror sweep per enabled store, scoped
     *  to that store's links so shops never stamp each other MISSING. */
    public function handle(ShopeeLiveListing $live): int
    {
        $stores = ShopeeSetting::query()->where('enabled', true)->orderBy('id')->get();
        if ($stores->isEmpty()) {
            $this->error('No enabled Shopee store. Configure Shopee settings first.');
            return self::FAILURE;
        }

        $worst = self::SUCCESS;
        foreach ($stores as $store) {
            $label = $store->store_name ?: ('#' . $store->id);
            $auth = ShopeeSetting::activeAuth($store->decrypted());
            if (!$auth['complete']) {
                $this->error("Store {$label}: missing Shopee credentials.");
                $worst = self::FAILURE;
                continue;
            }

            $result = $live->refreshMirror($auth, (int) $store->id);
            if ($result['error'] !== null) {
                $this->error("Store {$label}: Shopee did not answer: " . $result['error']);
                $worst = self::FAILURE;
                continue;
            }


            /* The cron does what the button does (the owner, 2026-09-07): the
             * statuses are re-read, THEN the links are checked. Before this
             * the scheduled refresh only re-read statuses, so a failed link
             * check was never revisited and a row wore a six-month-old
             * refusal until somebody pressed Refresh by hand. */
            $checked = app(\Extensions\shopee\Services\Shopee\ShopeeLinkCheck::class)
                ->runNext(app(\Extensions\shopee\Services\Shopee\ShopeeClient::class), $auth, (int) $store->id);

            // Bound for the read only: the store's own listings and groups name the categories.
            app()->instance('shopee.route-store', $store);
            try {
                $sheets = app(\Extensions\shopee\Services\Shopee\ShopeeSheetReads::class)
                    ->readMissing($auth, app(\Extensions\shopee\Services\Shopee\ShopeeClient::class));
            } finally {
                app()->forgetInstance('shopee.route-store');
            }

            $this->info(sprintf(
                'Store %s: refreshed %d link(s): %s.%s%s',
                $label,
                $result['links'],
                implode(', ', array_map(fn ($k, $v) => "{$v} {$k}", array_keys($result['counts']), $result['counts'])) ?: 'nothing on Shopee',
                $checked ? ' ' . $checked['summary'] : '',
                $sheets['summary'] !== '' ? ' ' . $sheets['summary'] : ''
            ));
        }

        return $worst;
    }
}
