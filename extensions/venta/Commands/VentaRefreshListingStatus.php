<?php

namespace Extensions\venta\Commands;

use Extensions\venta\Models\VentaSetting;
use Extensions\venta\Services\Venta\VentaListingMirror;
use Illuminate\Console\Command;

/**
 * Refresh the listing status mirror of a Venta store: one walk of the
 * store's product list, written onto every linked product's listing row.
 * Scheduled every 30 minutes per store (Automations) and run by the
 * Refresh button on the store's listings page.
 */
class VentaRefreshListingStatus extends Command
{
    protected $signature = 'venta:refresh-listing-status {--store= : One store id; every enabled store when omitted}';

    protected $description = 'Refresh the mirrored status (active, unlisted, missing) of every product linked to a Venta store';

    public function handle(): int
    {
        $stores = VentaSetting::query()->where('enabled', true);
        if ($this->option('store')) {
            $stores->where('id', (int) $this->option('store'));
        }

        $failed = 0;
        foreach ($stores->get() as $store) {
            $result = VentaListingMirror::for($store)->refresh();
            if ($result['error'] !== null) {
                $this->error($store->store_name . ': ' . $result['error']);
                $failed++;
                continue;
            }

            /* The cron does what the button does (the owner, 2026-09-07): the
             * statuses are re-read, THEN the links are checked. Before this
             * the scheduled refresh only re-read statuses, so a failed link
             * check was never revisited and a row wore a six-month-old
             * refusal until somebody pressed Refresh by hand. */
            $checked = app(\Extensions\venta\Services\Venta\VentaLinkCheck::class)->runNext($store);

            $this->info(sprintf(
                '%s: refreshed %d listing(s): %s.%s',
                $store->store_name,
                $result['links'],
                implode(', ', array_map(fn ($k, $v) => "{$v} {$k}", array_keys($result['counts']), $result['counts'])) ?: 'nothing linked',
                $checked ? ' ' . $checked['summary'] : ''
            ));
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
