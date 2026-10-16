<?php

namespace Extensions\OpenCart\Commands;

use Extensions\OpenCart\Models\OpenCartStore;
use Extensions\OpenCart\Services\OpenCart\OpenCartClient;
use Extensions\OpenCart\Services\OpenCart\OpenCartProductSync;
use Illuminate\Console\Command;

class OpenCartPullQty extends Command
{
    protected $signature = 'opencart:pull-qty
        {--store= : Store ID (ch_opencart_stores.id). Omit to pull from all enabled stores}';

    protected $description = 'Pull product quantities from OpenCart stores into VentaSync (quantity only, no other fields)';

    public function handle(): int
    {
        $storeId = $this->option('store');

        if ($storeId) {
            $settings = OpenCartStore::where('id', (int) $storeId)
                ->where('enabled', true)
                ->get();

            if ($settings->isEmpty()) {
                $this->error("Store #{$storeId} not found or disabled.");
                return 1;
            }
        } else {
            $settings = OpenCartStore::where('enabled', true)->get();

            if ($settings->isEmpty()) {
                $this->error('No enabled OpenCart stores configured.');
                return 1;
            }
        }

        foreach ($settings as $store) {
            $this->newLine();
            $this->info("=== Pulling quantities from: {$store->store_name} (#{$store->id}) ===");

            $client = new OpenCartClient($store);

            $ping = $client->ping();
            if (!$ping['ok']) {
                $this->error('Cannot connect: ' . json_encode($ping['body']));
                continue;
            }

            $sync = new OpenCartProductSync($client, $store);
            $log = $sync->pullQuantities();

            $this->info("  Status: {$log->status}");
            $this->info("  Updated: {$log->records_updated}, Failed: {$log->records_failed}");

            if ($log->error_message) {
                $this->error("  Error: {$log->error_message}");
            }
        }

        $this->newLine();
        $this->info('Done.');

        return 0;
    }
}
