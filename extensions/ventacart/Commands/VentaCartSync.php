<?php

namespace Extensions\ventacart\Commands;

use Extensions\ventacart\Models\VentaCartSetting;
use Extensions\ventacart\Services\VentaCart\VentaCartClient;
use Extensions\ventacart\Services\VentaCart\VentaCartOrderSync;
use Illuminate\Console\Command;

class VentaCartSync extends Command implements \App\Integrations\Contracts\SteppableAutomation
{
    protected $signature = 'ventacart:sync
        {entity : Entity to sync (orders)}
        {--store= : Store ID (ventacart_settings.id). Omit to sync all enabled stores}
        {--full : Full sync (ignore last sync timestamp)}
        {--max-pages=0 : Stop after N pages (0 = unlimited)}
        {--no-stock : Skip stock adjustments (use for initial import)}';

    protected $description = 'Sync data between VentaCart stores and the ERP catalog';

    public function handle(): int
    {
        $storeId = $this->option('store');
        $entity = $this->argument('entity');

        $allowed = ['orders'];
        if (!in_array($entity, $allowed)) {
            $this->error("Invalid entity '{$entity}'. Allowed: " . implode(', ', $allowed));
            return 1;
        }

        if ($storeId) {
            $settings = VentaCartSetting::where('id', (int) $storeId)
                ->where('enabled', true)
                ->get();

            if ($settings->isEmpty()) {
                $this->error("Store #{$storeId} not found or disabled.");
                return 1;
            }
        } else {
            $settings = VentaCartSetting::where('enabled', true)->get();

            if ($settings->isEmpty()) {
                $this->error('No enabled VentaCart stores configured.');
                return 1;
            }
        }

        foreach ($settings as $setting) {
            $this->newLine();
            $this->info("=== Store: {$setting->store_name} (#{$setting->id}) ===");
            $this->syncStore($setting, $entity);
        }

        $this->newLine();
        $this->info('Done.');
        return 0;
    }

    private function syncStore(VentaCartSetting $setting, string $entity): void
    {
        $client = new VentaCartClient($setting);

        $this->syncOrders($client, $setting);
    }

    private function syncOrders(VentaCartClient $client, VentaCartSetting $setting): void
    {
        $this->info('  Syncing orders...');

        $full = (bool) $this->option('full');
        $maxPages = (int) $this->option('max-pages');
        $noStock = (bool) $this->option('no-stock');

        $sync = new VentaCartOrderSync($client, $setting);

        if ($noStock) {
            $sync->setSkipStockAdjust(true);
        }

        $log = $sync->pull(
            full: $full,
            maxPages: $maxPages,
            onProgress: function ($processed) {
                $this->output->write("\r  Orders processed: {$processed}");
            }
        );

        $this->newLine();

        if ($log->status === 'failed') {
            $this->error('  Order sync failed: ' . ($log->error_message ?? 'Unknown'));
            return;
        }

        $parts = [];
        if ($log->records_created > 0) $parts[] = "{$log->records_created} created";
        if ($log->records_updated > 0) $parts[] = "{$log->records_updated} updated";
        if ($log->records_failed > 0)  $parts[] = "{$log->records_failed} failed";
        $this->info('  Orders: ' . (empty($parts) ? '0 records' : implode(', ', $parts)));
    }

    public function automationUnits(\App\Models\ScheduledJob $job): array
    {
        $stores = VentaCartSetting::query()->where('enabled', true);
        if ($job->getOption('store')) {
            $stores->where('id', (int) $job->getOption('store'));
        }
        $units = [];
        foreach ($stores->orderBy('id')->get() as $store) {
            $units[] = $store->id . ':orders';
        }

        return ['label' => 'stores', 'units' => $units];
    }

    public function automationChunk(): int
    {
        return 1;
    }

    public function automationStep(\App\Models\ScheduledJob $job, array $units): array
    {
        $ok = 0;
        $failed = 0;
        $notes = [];
        foreach ($units as $unit) {
            [$sid] = array_pad(explode(':', (string) $unit, 2), 2, '');
            $store = VentaCartSetting::query()->where('enabled', true)->find((int) $sid);
            if (! $store) {
                $failed++;
                continue;
            }
            $log = (new VentaCartOrderSync(new VentaCartClient($store), $store))->pull();
            if ($log->status === 'failed') {
                $failed++;
                $notes[] = ($store->store_name ?: '#' . $store->id) . ': ' . (string) ($log->error_message ?: 'VentaCart did not answer');
            } else {
                $ok++;
            }
        }

        return ['ok' => $ok, 'failed' => $failed, 'note' => implode('; ', array_slice($notes, 0, 2))];
    }
}
