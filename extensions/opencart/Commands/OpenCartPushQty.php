<?php

namespace Extensions\opencart\Commands;

use Extensions\opencart\Models\OpenCartSetting;
use Extensions\opencart\Services\OpenCart\OpenCartClient;
use Extensions\opencart\Services\OpenCart\OpenCartProductSync;
use Illuminate\Console\Command;

class OpenCartPushQty extends Command implements \App\Integrations\Contracts\SteppableAutomation
{
    protected $signature = 'opencart:push-qty
        {--store= : Store ID (opencart_settings.id). Omit to push to all enabled stores}';

    protected $description = 'Push product quantities from the ERP to OpenCart stores';

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
            $this->info("=== Pushing quantities to: {$setting->store_name} (#{$setting->id}) ===");

            try {
                $client = new OpenCartClient($setting);

                $ping = $client->ping();
                if (!$ping['ok']) {
                    $this->error('Cannot connect: ' . json_encode($ping['body']));
                    continue;
                }

                $sync = new OpenCartProductSync($client, $setting);
                $log = $sync->pushQuantities();

                $this->info("  Status: {$log->status}");
                $this->info("  Updated: {$log->records_updated}, Failed: {$log->records_failed}");

                if ($log->error_message) {
                    $this->error("  Error: {$log->error_message}");
                }
            } catch (\Throwable $e) {
                $this->error("  Error: " . $e->getMessage());
            }
        }

        $this->newLine();
        $this->info('Done.');

        return 0;
    }

    public function automationUnits(\App\Models\ScheduledJob $job): array
    {
        return ['label' => 'products', 'units' => \Extensions\opencart\Services\OpenCart\OpenCartPushSteps::units($job)];
    }

    public function automationStep(\App\Models\ScheduledJob $job, array $units): array
    {
        return \Extensions\opencart\Services\OpenCart\OpenCartPushSteps::step('stock', $units);
    }
}
