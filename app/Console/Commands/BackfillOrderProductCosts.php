<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillOrderProductCosts extends Command
{
    protected $signature = 'orders:backfill-costs
                            {--dry-run : Show what would be updated without saving}
                            {--force : Re-evaluate ALL order products, not just cost=0}';

    protected $description = 'Backfill order_product.cost from option value cost (preferred) or product cost';

    public function handle(): int
    {
        $pfx = (string) config('catalog.prefix');
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        if ($dryRun) {
            $this->warn('DRY RUN — no changes will be saved.');
        }

        // Step 1: Backfill from option value cost (base product cost + option cost delta)
        $this->info('Step 1: Resolving option-level costs (base + prefix delta)...');

        $optionQuery = DB::table($pfx . 'order_product as op')
            ->join($pfx . 'order_option as oo', function ($j) {
                $j->on('oo.order_id', '=', 'op.order_id')
                  ->on('oo.order_product_id', '=', 'op.order_product_id');
            })
            ->join($pfx . 'product_option_value as pov', 'pov.product_option_value_id', '=', 'oo.product_option_value_id')
            ->join($pfx . 'product as p', 'p.product_id', '=', 'op.product_id');

        if (!$force) {
            $optionQuery->where('op.cost', 0);
        }

        $optionRows = $optionQuery
            ->select('op.order_product_id', 'p.cost as base_cost', 'pov.cost as option_cost_delta', 'pov.cost_prefix')
            ->get();

        // Deduplicate: one order_product may have multiple options, pick the first
        $optionCosts = [];
        foreach ($optionRows as $row) {
            if (!isset($optionCosts[$row->order_product_id])) {
                $baseCost = (float) $row->base_cost;
                $delta = (float) $row->option_cost_delta;
                $fullCost = ($row->cost_prefix === '-')
                    ? $baseCost - $delta
                    : $baseCost + $delta;
                $optionCosts[$row->order_product_id] = $fullCost;
            }
        }

        $this->info('  Found ' . count($optionCosts) . ' order products with option-level costs.');

        $optionUpdated = 0;
        if (!$dryRun && count($optionCosts) > 0) {
            foreach ($optionCosts as $opId => $cost) {
                DB::table($pfx . 'order_product')
                    ->where('order_product_id', $opId)
                    ->update(['cost' => $cost]);
                $optionUpdated++;
            }
        }

        // Step 2: Backfill remaining from base product cost
        $this->info('Step 2: Resolving base product costs...');

        $baseQuery = DB::table($pfx . 'order_product as op')
            ->join($pfx . 'product as p', 'p.product_id', '=', 'op.product_id')
            ->where('op.cost', 0)
            ->where('p.cost', '>', 0);

        $baseCount = (clone $baseQuery)->count();
        $this->info("  Found {$baseCount} order products to update from base product cost.");

        if (!$dryRun && $baseCount > 0) {
            DB::statement("
                UPDATE `{$pfx}order_product` AS op
                INNER JOIN `{$pfx}product` AS p ON p.product_id = op.product_id
                SET op.cost = p.cost
                WHERE op.cost = 0
                  AND p.cost > 0
            ");
        }

        $remaining = DB::table($pfx . 'order_product')->where('cost', 0)->count();

        $this->newLine();
        $this->info('Results:');
        $this->info("  Option-level cost updates: " . ($dryRun ? count($optionCosts) . ' (would update)' : $optionUpdated));
        $this->info("  Base product cost updates: " . ($dryRun ? $baseCount . ' (would update)' : $baseCount));
        $this->info("  Remaining with cost=0: {$remaining}");

        if ($dryRun) {
            $this->warn('DRY RUN — no changes were saved. Remove --dry-run to apply.');
        }

        return 0;
    }
}
