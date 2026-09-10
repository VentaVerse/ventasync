<?php

namespace Extensions\venta\Commands;

use Extensions\venta\Models\VentaSetting;
use Extensions\venta\Services\Venta\VentaClient;
use Extensions\venta\Services\Venta\VentaOrderSync;
use Illuminate\Console\Command;

/**
 * COLLECT WHAT THE STOREFRONT NOW KNOWS ABOUT PAST ORDERS' FEES, AND
 * NOTHING ELSE.
 *
 * Venta started recording a payment fee and a delivery fee, and backfilled
 * its own past orders. The ERP cannot find them in what it already has:
 * an order's stored payload is a photograph of what the storefront sent at
 * the time, so those orders carry no fees to normalize. They have to be
 * asked for again.
 *
 * A full Sync orders would do it and would also do something the owner must
 * not discover afterwards: it deletes and re-inserts every order LINE and
 * looks each cost up from the catalogue as it is today. Re-syncing months
 * of orders to collect fees would re-stamp months of cost of goods at
 * today's costs and move every profit figure in the system.
 *
 * This asks for the same orders and takes only the money: the storefront's
 * answer is stored, the four fee codes are written onto the core order,
 * and the lines, costs, statuses, stock and history are left alone.
 *
 * Safe to run again, and safe to stop: each order is finished before the
 * next is asked for.
 */
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
                // Nothing about stock is this run's business.
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
