<?php

namespace Extensions\opencart\Commands;

use Extensions\opencart\Models\OpenCartSetting;
use Extensions\opencart\Services\OpenCart\OpenCartClient;
use Extensions\opencart\Services\OpenCart\OpenCartProductSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class OpenCartPush extends Command
{
    protected $signature = 'opencart:push
        {--store= : Store ID (opencart_settings.id). Omit to push to all enabled stores}
        {--product-ids= : Comma-separated product IDs to push}
        {--since= : Push products modified since this datetime (Y-m-d H:i:s)}
        {--all : Push all products}';

    protected $description = 'Push product updates from Laravel to OpenCart stores';

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

        $ids = $this->resolveProductIds();
        if ($ids === null) return 1;

        if (empty($ids)) {
            $this->warn('No products to push.');
            return 0;
        }

        foreach ($settings as $setting) {
            $this->newLine();
            $this->info("=== Pushing to: {$setting->store_name} (#{$setting->id}) ===");
            $this->info("Products: " . count($ids));

            $client = new OpenCartClient($setting);

            $ping = $client->ping();
            if (!$ping['ok']) {
                $this->error('Cannot connect: ' . json_encode($ping['body']));
                continue;
            }

            $sync = new OpenCartProductSync($client, $setting);
            $result = $sync->push($ids);

            $this->info("  Updated: {$result['updated']}, Created: {$result['created']}, Failed: {$result['failed']}");

            if (!empty($result['errors'])) {
                $shown = array_slice($result['errors'], 0, 5);
                foreach ($shown as $err) {
                    $this->error("  [#{$err['product_id']}] {$err['error']}");
                }
                if (count($result['errors']) > 5) {
                    $this->warn("  ... and " . (count($result['errors']) - 5) . " more errors");
                }
            }
        }

        $this->newLine();
        $this->info('Done.');

        return 0;
    }

    private function resolveProductIds(): ?array
    {
        $pfx = (string) config('catalog.prefix');

        if ($this->option('product-ids')) {
            $requestedIds = array_map('intval', explode(',', $this->option('product-ids')));
            return DB::table($pfx . 'product')
                ->whereIn('product_id', $requestedIds)
                ->where('status', 1)
                ->pluck('product_id')
                ->toArray();
        }

        if ($this->option('since')) {
            return DB::table($pfx . 'product')
                ->where('date_modified', '>=', $this->option('since'))
                ->where('status', 1)
                ->pluck('product_id')
                ->toArray();
        }

        if ($this->option('all')) {
            return DB::table($pfx . 'product')
                ->where('status', 1)
                ->pluck('product_id')
                ->toArray();
        }

        $this->error('Specify --product-ids, --since, or --all.');
        return null;
    }
}
