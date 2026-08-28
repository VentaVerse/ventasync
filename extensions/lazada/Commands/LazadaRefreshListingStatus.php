<?php

namespace Extensions\lazada\Commands;

use Extensions\lazada\Models\LazadaSetting;
use Extensions\lazada\Services\Lazada\LazadaLiveListing;
use Illuminate\Console\Command;

/**
 * Refresh the listing status mirror: what Lazada holds in each bucket,
 * written onto every listing row. Scheduled every 30 minutes (Automations)
 * and run by the Refresh button on the listings page.
 */
class LazadaRefreshListingStatus extends Command
{
    protected $signature = 'lazada:refresh-listing-status';

    protected $description = 'Refresh the mirrored Lazada status (active, inactive, pending, violation, sold out, deleted) of every uploaded listing';

    public function handle(LazadaLiveListing $live): int
    {
        $setting = LazadaSetting::query()->first()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$creds['complete']) {
            $this->error('Missing Lazada credentials. Configure Lazada settings first.');
            return self::FAILURE;
        }

        $result = $live->refreshMirror($setting, $creds);
        if ($result['error'] !== null) {
            $this->error('Lazada did not answer: ' . $result['error']);
            return self::FAILURE;
        }

        $this->info(sprintf(
            'Refreshed %d listing(s): %s.',
            $result['links'],
            implode(', ', array_map(fn ($k, $v) => "{$v} {$k}", array_keys($result['counts']), $result['counts'])) ?: 'nothing on Lazada'
        ));

        return self::SUCCESS;
    }
}
