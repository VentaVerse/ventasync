<?php

namespace Extensions\VentaCart\Commands;

use Extensions\VentaCart\Models\VentaCartStore;
use Extensions\VentaCart\Services\VentaCart\VentaCartClient;
use Extensions\VentaCart\Services\VentaCart\VentaCartOrderSync;
use Illuminate\Console\Command;

class VentaCartSync extends Command implements \App\Channels\Contracts\SteppableAutomation
{
    protected $signature = 'ventacart:sync
        {entity : Entity to sync (orders)}
        {--store= : Store ID (ch_ventacart_stores.id). Omit to sync all enabled stores}
        {--full : Full sync (ignore last sync timestamp)}
        {--max-pages=0 : Stop after N pages (0 = unlimited)}
        {--no-stock : Skip stock adjustments (use for initial import)}';

    protected $description = 'Sync data between VentaCart stores and VentaSync catalog';

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
            $settings = VentaCartStore::where('id', (int) $storeId)
                ->where('enabled', true)
                ->get();

            if ($settings->isEmpty()) {
                $this->error("Store #{$storeId} not found or disabled.");
                return 1;
            }
        } else {
            $settings = VentaCartStore::where('enabled', true)->get();

            if ($settings->isEmpty()) {
                $this->error('No enabled VentaCart stores configured.');
                return 1;
            }
        }

        foreach ($settings as $store) {
            $this->newLine();
            $this->info("=== Store: {$store->store_name} (#{$store->id}) ===");
            $this->syncStore($store, $entity);
        }

        $this->newLine();
        $this->info('Done.');
        return 0;
    }

    private function syncStore(VentaCartStore $store, string $entity): void
    {
        $client = new VentaCartClient($store);

        $this->syncOrders($client, $store);
    }

    private function syncOrders(VentaCartClient $client, VentaCartStore $store): void
    {
        $this->info('  Syncing orders...');

        $full = (bool) $this->option('full');
        $maxPages = (int) $this->option('max-pages');
        $noStock = (bool) $this->option('no-stock');

        $sync = new VentaCartOrderSync($client, $store);

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
        $stores = VentaCartStore::query()->where('enabled', true);
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
            $store = VentaCartStore::query()->where('enabled', true)->find((int) $sid);
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
