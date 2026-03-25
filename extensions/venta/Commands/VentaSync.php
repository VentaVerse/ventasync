<?php

namespace Extensions\venta\Commands;

use Extensions\venta\Models\VentaSetting;
use Extensions\venta\Services\Venta\VentaClient;
use Extensions\venta\Services\Venta\VentaOrderSync;
use Illuminate\Console\Command;

class VentaSync extends Command
{
    protected $signature = 'venta:sync
        {entity : Entity to sync (orders)}
        {--store= : Store ID (venta_settings.id). Omit to sync all enabled stores}
        {--full : Full sync (ignore last sync timestamp)}
        {--max-pages=0 : Stop after N pages (0 = unlimited)}
        {--no-stock : Skip stock adjustments (use for initial import)}';

    protected $description = 'Sync data between Venta stores and the ERP catalog';

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
            $settings = VentaSetting::where('id', (int) $storeId)
                ->where('enabled', true)
                ->get();

            if ($settings->isEmpty()) {
                $this->error("Store #{$storeId} not found or disabled.");
                return 1;
            }
        } else {
            $settings = VentaSetting::where('enabled', true)->get();

            if ($settings->isEmpty()) {
                $this->error('No enabled Venta stores configured.');
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

    private function syncStore(VentaSetting $setting, string $entity): void
    {
        $client = new VentaClient($setting);

        $this->syncOrders($client, $setting);
    }

    private function syncOrders(VentaClient $client, VentaSetting $setting): void
    {
        $this->info('  Syncing orders...');

        $full = (bool) $this->option('full');
        $maxPages = (int) $this->option('max-pages');
        $noStock = (bool) $this->option('no-stock');

        $sync = new VentaOrderSync($client, $setting);

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
}
