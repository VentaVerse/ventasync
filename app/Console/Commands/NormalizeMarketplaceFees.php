<?php

namespace App\Console\Commands;

use App\Services\Orders\MarketplaceFeeNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * PUT WHAT EACH CHANNEL ALREADY KNOWS ONTO THE ORDERS IT BELONGS TO.
 *
 * Every order sync normalizes its own fees from now on. This is for the
 * orders that came in before it did: it asks each channel what it charged
 * for an order it has already stored, and writes the four codes onto the
 * core order. No marketplace is called, so it costs nothing but time and
 * can be run again whenever more fee data arrives.
 *
 * WHY IT TOUCHES NOTHING ELSE, and this is the point of it existing rather
 * than telling somebody to re-sync: a re-sync deletes and re-inserts every
 * order LINE, and each line's cost is looked up from the catalogue as it
 * is today. Re-syncing a year of orders to collect their fees would
 * silently re-stamp a year of cost of goods at today's costs and move
 * every profit figure in the system. This writes four rows and reads
 * nothing else.
 */
class NormalizeMarketplaceFees extends Command
{
    protected $signature = 'orders:normalize-marketplace-fees
                            {--from= : YYYY-MM-DD, default 2026-02-01, when this ERP started}
                            {--to= : YYYY-MM-DD, default today}
                            {--chunk=500 : orders per pass}';

    protected $description = 'Write each channel\'s fees onto the core orders it already holds, for the reports to read';

    public function handle(MarketplaceFeeNormalizer $normalizer): int
    {
        $pfx = (string) config('catalog.prefix');
        /* February 2026 by default: this ERP's own orders start there and
           what came before is another system's, which the owner asked to leave
           alone (2026-09-10, "Starting from feb... Ignore legacy orders"). */
        $from = ($this->option('from') ?: '2026-02-01') . ' 00:00:00';
        $to = ($this->option('to') ?: now()->format('Y-m-d')) . ' 23:59:59';

        $orders = DB::table($pfx . 'order')
            ->whereBetween('date_added', [$from, $to])
            ->where('marketplace_source', '<>', '')
            ->orderBy('order_id')
            ->pluck('order_id');

        if ($orders->isEmpty()) {
            $this->info('No marketplace orders in that range.');

            return self::SUCCESS;
        }

        $this->info(sprintf('%s marketplace orders from %s to %s.',
            number_format($orders->count()), substr($from, 0, 10), substr($to, 0, 10)));

        $bar = $this->output->createProgressBar($orders->count());
        $bar->start();

        $written = 0;
        $silent = 0;
        foreach ($orders->chunk((int) $this->option('chunk')) as $chunk) {
            foreach ($chunk as $orderId) {
                $normalizer->forOrder((int) $orderId) ? $written++ : $silent++;
                $bar->advance();
            }
        }

        $bar->finish();
        $this->line('');
        $this->line('');
        $this->info(number_format($written) . ' orders now carry their channel\'s fees.');
        $this->line(number_format($silent) . ' had nothing recorded to write - no channel holds a fee payload for them,');
        $this->line('so their Fees column stays empty until those fees are fetched. Run');
        $this->line('`php artisan reports:fee-sources` to see that shape per channel.');

        return self::SUCCESS;
    }
}
