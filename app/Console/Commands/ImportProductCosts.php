<?php

namespace App\Console\Commands;

use App\Models\Catalog\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportProductCosts extends Command
{
    protected $signature = 'products:import-costs
                            {file : Path to CSV file (columns: sku,cost or product_id,cost)}
                            {--dry-run : Show what would be updated without saving}';

    protected $description = 'Import product costs from a CSV file exported from OpenCart';

    public function handle(): int
    {
        $file = $this->argument('file');
        $dryRun = $this->option('dry-run');

        if (!file_exists($file)) {
            $this->error("File not found: {$file}");
            return 1;
        }

        $handle = fopen($file, 'r');
        if (!$handle) {
            $this->error("Cannot open file: {$file}");
            return 1;
        }

        // Read header
        $header = fgetcsv($handle);
        if (!$header) {
            $this->error('CSV file is empty.');
            fclose($handle);
            return 1;
        }

        $header = array_map(fn ($h) => strtolower(trim($h)), $header);
        $hasSku = in_array('sku', $header);
        $hasProductId = in_array('product_id', $header);
        $hasCost = in_array('cost', $header);

        if (!$hasCost) {
            $this->error('CSV must have a "cost" column.');
            fclose($handle);
            return 1;
        }

        if (!$hasSku && !$hasProductId) {
            $this->error('CSV must have either a "sku" or "product_id" column.');
            fclose($handle);
            return 1;
        }

        $pfx = (string) config('catalog.prefix');
        $updated = 0;
        $skipped = 0;
        $notFound = 0;
        $row = 1;

        $lookupBy = $hasSku ? 'sku' : 'product_id';
        $this->info("Importing costs by {$lookupBy}...");

        if ($dryRun) {
            $this->warn('DRY RUN — no changes will be saved.');
        }

        while (($data = fgetcsv($handle)) !== false) {
            $row++;
            $record = array_combine($header, array_pad($data, count($header), ''));

            $cost = (float) ($record['cost'] ?? 0);
            $identifier = trim($record[$lookupBy] ?? '');

            if ($identifier === '' || $cost <= 0) {
                $skipped++;
                continue;
            }

            if ($lookupBy === 'sku') {
                $product = DB::table($pfx . 'product')->where('sku', $identifier)->first();
            } else {
                $product = DB::table($pfx . 'product')->where('product_id', (int) $identifier)->first();
            }

            if (!$product) {
                $notFound++;
                $this->line("  Row {$row}: {$lookupBy}={$identifier} — product not found");
                continue;
            }

            if (!$dryRun) {
                DB::table($pfx . 'product')
                    ->where('product_id', $product->product_id)
                    ->update(['cost' => $cost]);
            }

            $updated++;
            $this->line("  Row {$row}: {$lookupBy}={$identifier} → cost={$cost}");
        }

        fclose($handle);

        $this->newLine();
        $this->info("Done. Updated: {$updated}, Not found: {$notFound}, Skipped: {$skipped}");

        return 0;
    }
}
