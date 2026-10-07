<?php

namespace Extensions\opencart\Commands;

use Extensions\opencart\Models\OpenCartSetting;
use Extensions\opencart\Services\OpenCart\OpenCartClient;
use Extensions\opencart\Services\OpenCart\OpenCartProductSync;
use Illuminate\Console\Command;

class OpenCartPullQty extends Command
{
    protected $signature = 'opencart:pull-qty
        {--store= : Store ID (opencart_settings.id). Omit to pull from all enabled stores}';

    protected $description = 'Pull product quantities from OpenCart stores into the ERP (quantity only, no other fields)';

    public function handle(): int
    {
        $storeId = $this->option('store');

        if ($storeId) {
            $settings = OpenCartSetting::where('id', (int) $storeId)
                ->where('enabled', true)
                ->get();

            if ($settings->isEmpty()) {
                $this->error("Store #{$storeId} not found or disabled.");
                return 1;
            }
        } else {
            $settings = OpenCartSetting::where('enabled', true)->get();

            if ($settings->isEmpty()) {
                $this->error('No enabled OpenCart stores configured.');
                return 1;
            }
        }

        foreach ($settings as $setting) {
            $this->newLine();
            $this->info("=== Pulling quantities from: {$setting->store_name} (#{$setting->id}) ===");

            $client = new OpenCartClient($setting);

            $ping = $client->ping();
            if (!$ping['ok']) {
                $this->error('Cannot connect: ' . json_encode($ping['body']));
                continue;
            }

            $sync = new OpenCartProductSync($client, $setting);
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
