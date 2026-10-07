<?php

namespace Extensions\lazada\Commands;

use Extensions\lazada\Models\LazadaSetting;
use Extensions\lazada\Services\Lazada\LazadaLiveListing;
use Illuminate\Console\Command;

class LazadaRefreshListingStatus extends Command
{
    protected $signature = 'lazada:refresh-listing-status';

    protected $description = 'Refresh the mirrored Lazada status (active, inactive, pending, violation, sold out, deleted) of every uploaded listing';

    public function handle(LazadaLiveListing $live): int
    {
        $stores = \Extensions\lazada\Models\LazadaSetting::enabledStores();
        if ($stores->isEmpty()) { $this->error('No enabled Lazada store. Configure Lazada settings first.'); return 1; }
        $worst = 0;
        foreach ($stores as $store) {
            $label = $store->store_name ?: ('#' . $store->id);
            $this->info("=== Lazada store: {$label} ===");
            app()->instance('lazada.route-store', $store);
            try {
                $worst = max($worst, $this->handleStore($live, $store));
            } catch (\Throwable $e) {
                $this->error("Store {$label} failed: " . $e->getMessage());
                \Illuminate\Support\Facades\Log::error('Lazada '.class_basename($this).': store failed', ['store' => $store->id, 'error' => $e->getMessage()]);
                $worst = 1;
            }
        }
        app()->forgetInstance('lazada.route-store');
        return $worst;
    }

    private function handleStore(LazadaLiveListing $live, \Extensions\lazada\Models\LazadaSetting $store): int
    {
        $setting = $store->decrypted();
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


        $checked = app(\Extensions\lazada\Services\Lazada\LazadaLinkCheck::class)->runNext($setting, $creds);
        $sheets = app(\Extensions\lazada\Services\Lazada\LazadaSheetReads::class)->readMissing($setting, app(\Extensions\lazada\Services\Lazada\LazadaClient::class));

        $this->info(sprintf(
            'Refreshed %d listing(s): %s.%s%s',
            $result['links'],
            implode(', ', array_map(fn ($k, $v) => "{$v} {$k}", array_keys($result['counts']), $result['counts'])) ?: 'nothing on Lazada',
            $checked ? ' ' . $checked['summary'] : '',
            $sheets['summary'] !== '' ? ' ' . $sheets['summary'] : ''
        ));

        return self::SUCCESS;
    }
}
