<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportCostsFromSql extends Command
{
    protected $signature = 'products:import-costs-sql
                            {file : Path to OpenCart SQL dump file}
                            {store_id : OpenCart store ID (1=Shop A, 2=Shop B)}
                            {--dry-run : Show what would be updated without saving}';

    protected $description = 'Import product cost breakdown (amount, percentage, additional) from OpenCart SQL dump via opencart_product_links mapping';

    public function handle(): int
    {
        $file = $this->argument('file');
        $storeId = (int) $this->argument('store_id');
        $dryRun = $this->option('dry-run');

        if (!file_exists($file)) {
            $this->error("File not found: {$file}");
            return 1;
        }

        $storeName = DB::table('opencart_settings')->where('id', $storeId)->value('store_name') ?? "Store #{$storeId}";
        $this->info("Importing costs from: {$file}");
        $this->info("Store: {$storeName} (ID: {$storeId})");

        if ($dryRun) {
            $this->warn('DRY RUN — no changes will be saved.');
        }

        $content = file_get_contents($file);

        // Column order in 0012_product:
        //  0: product_id, 1: model, 2: sku, 3: upc, 4: ean, 5: jan, 6: isbn, 7: mpn,
        //  8: location, 9: quantity, 10: stock_status_id, 11: image, 12: manufacturer_id,
        // 13: supplier_id, 14: shipping, 15: price, 16: cost, 17: costing_method,
        // 18: cost_amount, 19: cost_percentage, 20: cost_additional
        //
        // Regex captures: product_id(1), price(2), cost(3), cost_amount(4), cost_percentage(5), cost_additional(6)
        preg_match_all(
            '/\((\d+),'                                      // product_id
            . '\s*\'[^\']*\','                                // model
            . '\s*\'[^\']*\','                                // sku
            . '\s*\'[^\']*\','                                // upc
            . '\s*\'[^\']*\','                                // ean
            . '\s*\'[^\']*\','                                // jan
            . '\s*\'[^\']*\','                                // isbn
            . '\s*\'[^\']*\','                                // mpn
            . '\s*\'[^\']*\','                                // location
            . '\s*[-\d]+,'                                    // quantity
            . '\s*\d+,'                                       // stock_status_id
            . '\s*\'[^\']*\','                                // image
            . '\s*\d+,'                                       // manufacturer_id
            . '\s*\d+,'                                       // supplier_id
            . '\s*\d+,'                                       // shipping
            . '\s*([\d.]+),'                                  // price
            . '\s*([\d.]+),'                                  // cost
            . '\s*\d+,'                                       // costing_method
            . '\s*([\d.]+),'                                  // cost_amount
            . '\s*([\d.]+),'                                  // cost_percentage
            . '\s*([\d.]+)/'                                  // cost_additional
            ,
            $content,
            $matches,
            PREG_SET_ORDER
        );

        $this->info("Parsed " . count($matches) . " products from SQL.");

        // Build OC product_id → cost data map
        $ocCosts = [];
        foreach ($matches as $m) {
            $ocProductId = (int) $m[1];
            $cost        = (float) $m[3];
            $costAmount  = (float) $m[4];
            $costPct     = (float) $m[5];
            $costAdd     = (float) $m[6];

            // Skip products with no cost data at all
            if ($cost <= 0 && $costAmount <= 0 && $costPct <= 0 && $costAdd <= 0) {
                continue;
            }

            $ocCosts[$ocProductId] = [
                'cost'            => $cost,
                'cost_amount'     => $costAmount,
                'cost_percentage' => $costPct,
                'cost_additional' => $costAdd,
            ];
        }

        $this->info("Products with cost data: " . count($ocCosts));

        if (empty($ocCosts)) {
            $this->warn('No products with cost data found.');
            return 0;
        }

        // Get product links for this store
        $links = DB::table('opencart_product_links')
            ->where('opencart_setting_id', $storeId)
            ->whereIn('oc_product_id', array_keys($ocCosts))
            ->pluck('product_id', 'oc_product_id')
            ->toArray();

        $this->info("Matched via opencart_product_links: " . count($links));

        $pfx = (string) config('catalog.prefix');
        $updated = 0;
        $notLinked = 0;

        $this->newLine();
        $headers = ['OC ID', 'ERP ID', 'Cost Amount', 'Cost %', 'Additional', 'Total Cost'];
        $rows = [];

        foreach ($ocCosts as $ocProductId => $data) {
            $erpProductId = $links[$ocProductId] ?? null;

            if (!$erpProductId) {
                $notLinked++;
                continue;
            }

            if (!$dryRun) {
                DB::table($pfx . 'product')
                    ->where('product_id', $erpProductId)
                    ->update([
                        'cost'            => $data['cost'],
                        'cost_amount'     => $data['cost_amount'],
                        'cost_percentage' => $data['cost_percentage'],
                        'cost_additional' => $data['cost_additional'],
                    ]);
            }

            $rows[] = [
                $ocProductId,
                $erpProductId,
                number_format($data['cost_amount'], 4),
                number_format($data['cost_percentage'], 2) . '%',
                number_format($data['cost_additional'], 4),
                number_format($data['cost'], 4),
            ];

            $updated++;
        }

        if ($updated <= 50 && !empty($rows)) {
            $this->table($headers, $rows);
        }

        $this->newLine();
        $this->info("Updated: {$updated}");
        $this->info("Not linked (no opencart_product_links match): {$notLinked}");

        if ($dryRun) {
            $this->warn('DRY RUN — no changes were saved. Remove --dry-run to apply.');
        }

        return 0;
    }
}
