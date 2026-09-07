<?php

namespace Extensions\opencart\Commands;

use Extensions\opencart\Models\OpenCartSetting;
use Extensions\opencart\Services\OpenCart\OpenCartClient;
use Extensions\opencart\Services\OpenCart\OpenCartProductSync;
use Illuminate\Console\Command;

/**
 * The "Push Price" automation (the owner, 2026-09-07). The old
 * stock-and-price row sent quantities only; price is its own row now, on
 * every channel.
 */
class OpenCartPushPrice extends Command
{
    protected $signature = 'opencart:push-price
        {--store= : Store ID (opencart_settings.id). Omit to push to all enabled stores}';

    protected $description = 'Push product prices from the ERP to OpenCart stores';

    public function handle(): int
    {
        $stores = OpenCartSetting::query()->where('enabled', true);
        if ($this->option('store')) {
            $stores->where('id', (int) $this->option('store'));
        }
        $stores = $stores->get();
        if ($stores->isEmpty()) {
            $this->error($this->option('store') ? "Store #{$this->option('store')} not found or disabled." : 'No enabled OpenCart stores configured.');

            return 1;
        }

        foreach ($stores as $setting) {
            $this->newLine();
            $this->info("=== Pushing prices to: {$setting->store_name} (#{$setting->id}) ===");
            try {
                $client = new OpenCartClient($setting);
                $ping = $client->ping();
                if (! $ping['ok']) {
                    $this->error('Cannot connect: ' . json_encode($ping['body']));
                    continue;
                }
                $log = (new OpenCartProductSync($client, $setting))->pushPrices();
                $this->info("  Status: {$log->status}");
                $this->info("  Updated: {$log->records_updated}, Failed: {$log->records_failed}");
                if ($log->error_message) {
                    $this->error("  Error: {$log->error_message}");
                }
            } catch (\Throwable $e) {
                $this->error('  Error: ' . $e->getMessage());
            }
        }

        $this->newLine();
        $this->info('Done.');

        return 0;
    }
}
