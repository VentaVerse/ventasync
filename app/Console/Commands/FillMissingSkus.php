<?php

namespace App\Console\Commands;

use App\Support\Catalog\SkuFallback;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FillMissingSkus extends Command
{
    protected $signature = 'catalog:fill-missing-skus {--apply : write the codes, instead of listing what is missing}';

    protected $description = 'Give an SKU to every catalog product that has none, from its own product id';

    public function handle(): int
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $rows = DB::table($pfx . 'product as p')
            ->leftJoin($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')->where('pd.language_id', '=', $langId);
            })
            ->where(fn ($q) => $q->whereNull('p.sku')->orWhere('p.sku', ''))
            ->orderBy('p.product_id')
            ->get(['p.product_id', 'pd.name']);

        if ($rows->isEmpty()) {
            $this->info('Every product carries an SKU.');

            return self::SUCCESS;
        }

        $this->line($rows->count() . ' product(s) carry no SKU:');
        foreach ($rows as $row) {
            $this->line(sprintf('  #%d  %s', $row->product_id, html_entity_decode((string) $row->name)));
        }

        if (! $this->option('apply')) {
            $this->line('');
            $this->info('Nothing was changed. Run it again with --apply to give each one its own id as its SKU.');

            return self::SUCCESS;
        }

        $products = 0;
        $variations = 0;
        foreach ($rows as $row) {
            $minted = SkuFallback::fill((int) $row->product_id);
            $products += $minted['parent'] !== null ? 1 : 0;
            $variations += (int) $minted['variations'];
        }

        $this->info($products . ' product SKU(s) and ' . $variations . ' variation SKU(s) written. They go up with the next push.');

        return self::SUCCESS;
    }
}
