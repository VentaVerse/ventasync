<?php

namespace Extensions\ventacart\Commands;

use Extensions\ventacart\Models\VentaCartSetting;
use Extensions\ventacart\Services\VentaCart\VentaCartListingMirror;
use Illuminate\Console\Command;

class VentaCartRefreshListingStatus extends Command
{
    protected $signature = 'ventacart:refresh-listing-status {--store= : One store id; every enabled store when omitted}';

    protected $description = 'Refresh the mirrored status (active, unlisted, missing) of every product linked to a VentaCart store';

    public function handle(): int
    {
        $stores = VentaCartSetting::query()->where('enabled', true);
        if ($this->option('store')) {
            $stores->where('id', (int) $this->option('store'));
        }

        $failed = 0;
        foreach ($stores->get() as $store) {
            $result = VentaCartListingMirror::for($store)->refresh();
            if ($result['error'] !== null) {
                $this->error($store->store_name . ': ' . $result['error']);
                $failed++;
                continue;
            }

            $checked = app(\Extensions\ventacart\Services\VentaCart\VentaCartLinkCheck::class)->runNext($store);

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
