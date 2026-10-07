<?php

namespace Extensions\venta\Commands;

use Extensions\venta\Models\VentaSetting;
use Extensions\venta\Services\Venta\VentaListingMirror;
use Illuminate\Console\Command;

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
