<?php

namespace Extensions\ventacart\Commands;

use Extensions\ventacart\Models\VentaCartSetting;
use Extensions\ventacart\Services\VentaCart\VentaCartClient;
use Extensions\ventacart\Services\VentaCart\VentaCartOrderSync;
use Illuminate\Console\Command;

class VentaCartRefreshOrderFees extends Command
{
    protected $signature = 'ventacart:refresh-order-fees
                            {--store= : one store id, default every enabled store}
                            {--from= : YYYY-MM-DD, default 2026-02-01}
                            {--pages=200 : a ceiling, so one run cannot walk forever}';

    protected $description = 'Ask VentaCart again for past orders and write ONLY their fees onto the core orders, leaving lines and costs untouched';

    public function handle(): int
    {
        $from = $this->option('from') ?: '2026-02-01';

        $stores = VentaCartSetting::query()->where('enabled', true)
            ->when($this->option('store'), fn ($q) => $q->where('id', (int) $this->option('store')))
            ->orderBy('id')->get();

        if ($stores->isEmpty()) {
            $this->error('No enabled VentaCart store. Turn syncing on first, or name one with --store.');

            return self::FAILURE;
        }

        foreach ($stores as $store) {
            $this->line('');
            $this->info($store->store_name . ' - asking for orders since ' . $from . '.');

            $sync = (new VentaCartOrderSync(new VentaCartClient($store), $store))
                ->setFeesOnly()
                ->setSkipStockAdjust();

            $log = $sync->pull(since: $from, full: true, maxPages: (int) $this->option('pages'));

            if ($log->status === 'failed') {
                $this->error('  ' . ($log->error_message ?: 'VentaCart did not answer.'));

                continue;
            }

            $this->line(sprintf('  %s orders read, fees written where the storefront had any.',
                number_format((int) $log->records_processed)));
        }

        $this->line('');
        $this->line('Check what landed with `php artisan reports:fee-sources --from=' . $from . '`.');
        $this->line('Nothing here touched an order line, a cost, a status or stock.');

        return self::SUCCESS;
    }
}
