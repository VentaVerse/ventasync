<?php

namespace Extensions\opencart\Commands;

use Extensions\opencart\Models\OpenCartSetting;
use Extensions\opencart\Services\OpenCart\OpenCartClient;
use Extensions\opencart\Services\OpenCart\OpenCartCategorySync;
use Extensions\opencart\Services\OpenCart\OpenCartManufacturerSync;
use Extensions\opencart\Services\OpenCart\OpenCartOptionSync;
use Extensions\opencart\Services\OpenCart\OpenCartOrderSync;
use Extensions\opencart\Services\OpenCart\OpenCartProductSync;
use Illuminate\Console\Command;

class OpenCartSync extends Command implements \App\Integrations\Contracts\SteppableAutomation
{
    protected $signature = 'opencart:sync
        {entity : Entity to sync (products|orders|categories|manufacturers|options|all)}
        {--store= : Store ID (opencart_settings.id). Omit to sync all enabled stores}
        {--full : Full sync (ignore last sync timestamp)}
        {--page=1 : Start page for resuming interrupted imports}
        {--max-pages=0 : Stop after N pages (0 = unlimited, useful for testing)}
        {--no-stock : Skip stock adjustments (use for initial import)}
        {--product-id= : Import a single product by its OpenCart product_id}';

    protected $description = 'Sync data between OpenCart and Laravel catalog';

    public function handle(): int
    {
        $storeId = $this->option('store');
        $entity = $this->argument('entity');

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
            $this->info("=== Store: {$setting->store_name} (#{$setting->id}) ===");

            $exitCode = $this->syncStore($setting, $entity);
            if ($exitCode !== 0) return $exitCode;
        }

        $this->newLine();
        $this->info('Done.');
        return 0;
    }

    private function syncStore(OpenCartSetting $setting, string $entity): int
    {
        $client = new OpenCartClient($setting);
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
            ? ['categories', 'manufacturers', 'options', 'products', 'orders']
            : [$entity];

        $categoryMap = [];
        $manufacturerMap = [];
        $optionMap = [];
        $optionValueMap = [];

        foreach ($entities as $e) {
            $this->newLine();
            $this->info("Syncing {$e}...");

            switch ($e) {
                case 'categories':
                    $sync = new OpenCartCategorySync($client, $setting);
                    $result = $sync->pull(null, $full);
                    $log = $result['log'];
                    $categoryMap = $result['map'];
                    break;

                case 'manufacturers':
                    $sync = new OpenCartManufacturerSync($client, $setting);
                    $result = $sync->pull();
                    $log = $result['log'];
                    $manufacturerMap = $result['map'];
                    break;

                case 'options':
                    $sync = new OpenCartOptionSync($client, $setting);
                    $result = $sync->pull();
                    $log = $result['log'];
                    $optionMap = $result['optionMap'];
                    $optionValueMap = $result['optionValueMap'];
                    break;

                case 'products':
                    $sync = new OpenCartProductSync($client, $setting);
                    $sync->setCategoryMap($categoryMap);
                    $sync->setManufacturerMap($manufacturerMap);
                    $sync->setOptionMap($optionMap);
                    $sync->setOptionValueMap($optionValueMap);
                    $productId = $this->option('product-id') ? (int) $this->option('product-id') : null;
                    $log = $sync->pull(null, $full, $productId);
                    break;

                case 'orders':
                    $sync = new OpenCartOrderSync($client, $setting);
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
                    $id = $err['product_id'] ?? $err['order_id'] ?? $err['category_id'] ?? $err['manufacturer_id'] ?? $err['option_id'] ?? '?';
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
        $stores = OpenCartSetting::query()->where('enabled', true);
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
            $store = OpenCartSetting::query()->where('enabled', true)->find((int) $sid);
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
