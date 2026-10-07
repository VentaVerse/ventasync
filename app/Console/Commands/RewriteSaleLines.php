<?php

namespace App\Console\Commands;

use App\Services\Orders\SaleLinesWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RewriteSaleLines extends Command
{
    protected $signature = 'orders:rewrite-sale-lines
                            {--from= : YYYY-MM-DD, default 2026-02-01}
                            {--to= : YYYY-MM-DD, default today}
                            {--apply : Rewrite the lines; without it the command only reports how many orders it would read}
                            {--chunk=500 : orders per pass}';

    protected $description = "Rewrite channel orders' Sale lines from each channel's stored data";

    public function handle(SaleLinesWriter $writer): int
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
            $this->info('No channel orders in that range.');

            return self::SUCCESS;
        }

        if (! $this->option('apply')) {
            $this->info(number_format($orders->count()) . ' channel orders from ' . substr($from, 0, 10) . ' to ' . substr($to, 0, 10) . '. Run with --apply to rewrite their Sale lines.');

            return self::SUCCESS;
        }

        $written = 0;
        $skipped = 0;
        foreach ($orders->chunk((int) $this->option('chunk')) as $chunk) {
            foreach ($chunk as $orderId) {
                $writer->forOrder((int) $orderId) ? $written++ : $skipped++;
            }
        }

        $this->info(number_format($written) . ' orders have their Sale lines rewritten.');
        $this->line(number_format($skipped) . ' orders belong to a channel with no Sale lines to give, and were left alone.');

        return self::SUCCESS;
    }
}
