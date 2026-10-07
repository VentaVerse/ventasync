<?php

namespace App\Console\Commands;

use App\Services\Catalog\ProductDeleter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PurgeOrphanProductRows extends Command
{
    protected $signature = 'catalog:purge-orphan-rows {--apply : Delete the rows; without it the command only lists them}';

    protected $description = 'List (or with --apply, delete) variation, image and special rows whose catalog product no longer exists';

    public function handle(): int
    {
        $pfx = (string) config('catalog.prefix');
        $orphans = [];
        foreach (ProductDeleter::OWNED_TABLES as $table) {
            if (! Schema::hasTable($pfx . $table)) {
                continue;
            }
            $ids = DB::table($pfx . $table . ' as x')
                ->leftJoin($pfx . 'product as p', 'p.product_id', '=', 'x.product_id')
                ->whereNull('p.product_id')
                ->distinct()->pluck('x.product_id')->map(fn ($v) => (int) $v)->all();
            $orphans[$table] = $ids;
        }
        $comboOwners = DB::table('product_option_combinations as c')
            ->leftJoin($pfx . 'product as p', 'p.product_id', '=', 'c.product_id')
            ->whereNull('p.product_id')
            ->distinct()->pluck('c.product_id')->map(fn ($v) => (int) $v)->all();
        $orphans['product_option_combinations'] = $comboOwners;

        $products = array_values(array_unique(array_merge(...array_values($orphans))));
        if ($products === []) {
            $this->info('No orphan rows: every variation, image and special belongs to a product that exists.');

            return self::SUCCESS;
        }
        foreach ($orphans as $table => $ids) {
            if ($ids !== []) {
                $this->line(sprintf('%-30s rows of %d deleted product(s): %s', $table, count($ids), implode(', ', array_map(fn ($i) => '#' . $i, array_slice($ids, 0, 20))) . (count($ids) > 20 ? ', ...' : '')));
            }
        }
        if (! $this->option('apply')) {
            $this->info(count($products) . ' deleted product(s) left rows behind. Run again with --apply to remove them.');

            return self::SUCCESS;
        }
        foreach ($products as $pid) {
            ProductDeleter::forgetOwnedRows($pfx, $pid);
        }
        $this->info('Removed the rows of ' . count($products) . ' deleted product(s).');

        return self::SUCCESS;
    }
}
