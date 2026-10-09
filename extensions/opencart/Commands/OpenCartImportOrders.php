<?php

namespace Extensions\OpenCart\Commands;

use Extensions\OpenCart\Models\OpenCartStore;
use Extensions\OpenCart\Services\OpenCart\OpenCartClient;
use Extensions\OpenCart\Services\OpenCart\OpenCartOrderSync;
use Illuminate\Console\Command;

class OpenCartImportOrders extends Command
{
    protected $signature = 'opencart:import-orders
        {--page=1 : Resume from this page}
        {--limit=100 : Orders per page}';

    protected $description = 'Import all historical orders from OpenCart (resumable)';

    public function handle(): int
    {
        $store = OpenCartStore::query()->first();

        if (!$store || !$store->enabled) {
            $this->error('OpenCart connection not configured or disabled.');
            return 1;
        }

        $client = new OpenCartClient($store);
        $startPage = (int) $this->option('page');

        if ($startPage === 1 && $store->last_order_page > 0) {
            $this->warn("Previous import stopped at page {$store->last_order_page}.");
            if ($this->confirm("Resume from page {$store->last_order_page}?", true)) {
                $startPage = (int) $store->last_order_page;
            }
        }

        $this->info("Starting historical order import from page {$startPage}...");
        $this->info("This may take a while for large datasets. You can interrupt (Ctrl+C) and resume later.");
        $this->newLine();

        $bar = null;

        $sync = new OpenCartOrderSync($client, $store);
        $log = $sync->pull(
            null,
            $startPage,
            function (int $processed, int $total) use (&$bar) {
                if ($bar === null && $total > 0) {
                    $bar = $this->output->createProgressBar($total);
                    $bar->start();
                }
                if ($bar) {
                    $bar->setProgress($processed);
                }
            }
        );

        if ($bar) {
            $bar->finish();
        }

        $this->newLine(2);
        $this->info("Import {$log->status}.");
        $this->info("  Created: {$log->records_created}, Updated: {$log->records_updated}, Failed: {$log->records_failed}");

        if ($log->error_message) {
            $this->error("Error: {$log->error_message}");
        }

        if ($log->records_failed > 0 && $log->details) {
            $this->newLine();
            $this->warn("Failed orders:");
            foreach (array_slice($log->details, 0, 10) as $err) {
                $this->line("  Order #{$err['order_id']}: {$err['error']}");
            }
            if (count($log->details) > 10) {
                $this->line("  ... and " . (count($log->details) - 10) . " more.");
            }
        }

        return 0;
    }
}
