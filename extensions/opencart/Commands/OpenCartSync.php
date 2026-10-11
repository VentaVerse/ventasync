<?php

namespace Extensions\OpenCart\Commands;

use Extensions\OpenCart\Models\OpenCartStore;
use Extensions\OpenCart\Services\OpenCart\OpenCartClient;
use Extensions\OpenCart\Services\OpenCart\OpenCartCategorySync;
use Extensions\OpenCart\Services\OpenCart\OpenCartBrandSync;
use Extensions\OpenCart\Services\OpenCart\OpenCartVariationTypeSync;
use Extensions\OpenCart\Services\OpenCart\OpenCartOrderSync;
use Extensions\OpenCart\Services\OpenCart\OpenCartProductSync;
use Illuminate\Console\Command;

class OpenCartSync extends Command implements \App\Channels\Contracts\SteppableAutomation
{
    protected $signature = 'opencart:sync
        {entity : Entity to sync (products|orders|categories|brands|variations|all)}
        {--store= : Store ID (ch_opencart_stores.id). Omit to sync all enabled stores}
        {--full : Full sync (ignore last sync timestamp)}
        {--page=1 : Start page for resuming interrupted imports}
        {--max-pages=0 : Stop after N pages (0 = unlimited, useful for testing)}
        {--no-stock : Skip stock adjustments (use for initial import)}
        {--product-id= : Import a single product by its OpenCart product_id}';

    protected $description = 'Sync data between OpenCart and Laravel catalog';

    public function handle(): int
    {
        $storeId = $this->option('store');
        $entity = ['manufacturers' => 'brands', 'options' => 'variations'][$this->argument('entity')] ?? $this->argument('entity');

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
            $this->info("=== Store: {$store->store_name} (#{$store->id}) ===");

            $exitCode = $this->syncStore($store, $entity);
            if ($exitCode !== 0) return $exitCode;
        }

        $this->newLine();
        $this->info('Done.');
        return 0;
    }

    private function syncStore(OpenCartStore $store, string $entity): int
    {
        $client = new OpenCartClient($store);
        $full = $this->option('full');
        $startPage = (int) $this->option('page');
        $maxPages = (int) $this->option('max-pages');

        $ping = $client->ping();
        if (!$ping['ok']) {
            $this->error('Cannot connect to OpenCart API: ' . json_encode($ping['body']));
            return 1;
        }

        $info = $ping['body']['data'] ?? [];
        $this->info('Connected. Store: ' . ($info['store'] ?? '?') . ', Version: ' . ($info['version'] ?? '?'));

        $entities = $entity === 'all'
            ? ['categories', 'brands', 'variations', 'products', 'orders']
            : [$entity];

        $categoryMap = [];
        $brandMap = [];
        $variationTypeMap = [];
        $variationValueMap = [];

        foreach ($entities as $e) {
            $this->newLine();
            $this->info("Syncing {$e}...");

            switch ($e) {
                case 'categories':
                    $sync = new OpenCartCategorySync($client, $store);
                    $result = $sync->pull(null, $full);
                    $log = $result['log'];
                    $categoryMap = $result['map'];
                    break;

                case 'brands':
                    $sync = new OpenCartBrandSync($client, $store);
                    $result = $sync->pull();
                    $log = $result['log'];
                    $brandMap = $result['map'];
                    break;

                case 'variations':
                    $sync = new OpenCartVariationTypeSync($client, $store);
                    $result = $sync->pull();
                    $log = $result['log'];
                    $variationTypeMap = $result['variationTypeMap'];
                    $variationValueMap = $result['variationValueMap'];
                    break;

                case 'products':
                    $sync = new OpenCartProductSync($client, $store);
                    $sync->setCategoryMap($categoryMap);
                    $sync->setBrandMap($brandMap);
                    $sync->setVariationTypeMap($variationTypeMap);
                    $sync->setVariationValueMap($variationValueMap);
                    $productId = $this->option('product-id') ? (int) $this->option('product-id') : null;
                    $log = $sync->pull(null, $full, $productId);
                    break;

                case 'orders':
                    $sync = new OpenCartOrderSync($client, $store);
                    if ($this->option('no-stock')) {
                        $sync->setSkipStockAdjust(true);
                    }
                    $log = $sync->pull(
                        null,
                        $startPage,
                        fn($processed, $total) => $this->output->write("\r  Processed {$processed}/{$total}"),
                        $full,
                        $maxPages
                    );
                    break;

                default:
                    $this->error("Unknown entity: {$e}");
                    continue 2;
            }

            $this->newLine();
            $this->info("  Status: {$log->status}");
            $this->info("  Processed: {$log->records_processed}, Created: {$log->records_created}, Updated: {$log->records_updated}, Failed: {$log->records_failed}");

            if ($log->error_message) {
                $this->error("  Error: {$log->error_message}");
            }

            if (!empty($log->details) && is_array($log->details)) {
                $shown = array_slice($log->details, 0, 5);
                foreach ($shown as $err) {
                    $id = $err['product_id'] ?? $err['order_id'] ?? $err['category_id'] ?? $err['brand_id'] ?? $err['variation_type_id'] ?? '?';
                    $this->error("  [{$id}] {$err['error']}");
                }
                if (count($log->details) > 5) {
                    $this->warn("  ... and " . (count($log->details) - 5) . " more errors");
                }
            }
        }

        return 0;
    }

    public function automationUnits(\App\Models\ScheduledJob $job): array
    {
        $stores = OpenCartStore::query()->where('enabled', true);
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
            $store = OpenCartStore::query()->where('enabled', true)->find((int) $sid);
            if (! $store) {
                $failed++;
                continue;
            }
            $log = (new OpenCartOrderSync(new OpenCartClient($store), $store))->pull();
            if ($log->status === 'failed') {
                $failed++;
                $notes[] = ($store->store_name ?: '#' . $store->id) . ': ' . (string) ($log->error_message ?: 'OpenCart did not answer');
            } else {
                $ok++;
            }
        }

        return ['ok' => $ok, 'failed' => $failed, 'note' => implode('; ', array_slice($notes, 0, 2))];
    }
}
