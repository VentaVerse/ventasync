<?php

namespace App\Console\Commands;

use App\Services\Orders\MarketplaceFeeNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// Never re-sync to collect fees: a re-sync re-stamps every order line's cost at today's catalogue costs.
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
