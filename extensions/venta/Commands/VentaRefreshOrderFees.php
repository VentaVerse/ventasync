<?php

namespace Extensions\venta\Commands;

use Extensions\venta\Models\VentaSetting;
use Extensions\venta\Services\Venta\VentaClient;
use Extensions\venta\Services\Venta\VentaOrderSync;
use Illuminate\Console\Command;

class VentaRefreshOrderFees extends Command
{
    protected $signature = 'venta:refresh-order-fees
                            {--store= : one store id, default every enabled store}
                            {--from= : YYYY-MM-DD, default 2026-02-01}
                            {--pages=200 : a ceiling, so one run cannot walk forever}';

    protected $description = 'Ask Venta again for past orders and write ONLY their fees onto the core orders, leaving lines and costs untouched';

    public function handle(): int
    {
        $from = $this->option('from') ?: '2026-02-01';

        $stores = VentaSetting::query()->where('enabled', true)
            ->when($this->option('store'), fn ($q) => $q->where('id', (int) $this->option('store')))
            ->orderBy('id')->get();

        if ($stores->isEmpty()) {
            $this->error('No enabled Venta store. Turn syncing on first, or name one with --store.');

            return self::FAILURE;
        }

        foreach ($stores as $store) {
            $this->line('');
            $this->info($store->store_name . ' - asking for orders since ' . $from . '.');

            $sync = (new VentaOrderSync(new VentaClient($store), $store))
                ->setFeesOnly()
                ->setSkipStockAdjust();

            $log = $sync->pull(since: $from, full: true, maxPages: (int) $this->option('pages'));

            if ($log->status === 'failed') {
                $this->error('  ' . ($log->error_message ?: 'Venta did not answer.'));

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
