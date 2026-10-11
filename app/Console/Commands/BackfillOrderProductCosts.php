<?php

namespace App\Console\Commands;

use App\Support\LineCost;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillOrderProductCosts extends Command
{
    protected $signature = 'orders:backfill-costs
                            {--apply : Write the changes. Without it the run only reports}
                            {--force : Re-read every line, not just the ones at cost 0}
                            {--from= : Only orders placed on or after this date (YYYY-MM-DD)}';

    protected $description = 'Re-read order line costs from the catalog: the variation\'s own cost when it has one, else the parent\'s';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $force = (bool) $this->option('force');
        $from = $this->option('from');

        if ($from !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $from)) {
            $this->error('--from must be a date, YYYY-MM-DD.');
            return self::INVALID;
        }

        if (!$apply) {
            $this->warn('Reporting only. Add --apply to write.');
        }

        $query = DB::table('order_products as op')
            ->join('orders as o', 'o.order_id', '=', 'op.order_id')
            ->where('op.product_id', '>', 0)
            ->orderBy('op.order_product_id');

        if (!$force) {
            $query->where('op.cost', 0);
        }
        if ($from !== null) {
            $query->where('o.date_added', '>=', $from . ' 00:00:00');
        }

        $variationByLine = DB::table('order_variations as oo')
            ->join('order_products as op', 'op.order_product_id', '=', 'oo.order_product_id')
            ->where('oo.product_variation_value_id', '>', 0)
            ->orderBy('oo.order_variation_id')
            ->pluck('oo.product_variation_value_id', 'oo.order_product_id')
            ->all();

        $names = DB::table('product_descriptions')
            ->where('language_id', (int) config('catalog.default_language_id', 1))
            ->pluck('name', 'product_id')
            ->all();

        $changes = [];
        $examined = 0;
        $changed = 0;
        $unresolved = 0;

        foreach ($query->select('op.order_product_id', 'op.product_id', 'op.cost')->cursor() as $line) {
            $examined++;
            $variationValueId = (int) ($variationByLine[$line->order_product_id] ?? 0);
            $new = LineCost::resolve((int) $line->product_id, $variationValueId);
            $old = (float) $line->cost;

            if ($new <= 0) {
                if ($old <= 0) {
                    $unresolved++;
                }
                continue;
            }
            if (abs($new - $old) < 0.00005) {
                continue;
            }

            $changed++;
            $pid = (int) $line->product_id;
            $changes[$pid] ??= ['lines' => 0, 'from' => 0.0, 'to' => 0.0];
            $changes[$pid]['lines']++;
            $changes[$pid]['from'] += $old;
            $changes[$pid]['to'] += $new;

            if ($apply) {
                DB::table('order_products')
                    ->where('order_product_id', $line->order_product_id)
                    ->update(['cost' => round($new, 4)]);
            }
        }

        if ($changes !== []) {
            uasort($changes, fn ($a, $b) => $b['lines'] <=> $a['lines']);
            $this->table(
                ['Product', 'Lines', 'Cost before (sum)', 'Cost after (sum)'],
                array_map(fn ($pid, $c) => [
                    ($names[$pid] ?? '#' . $pid),
                    $c['lines'],
                    number_format($c['from'], 2),
                    number_format($c['to'], 2),
                ], array_keys($changes), $changes)
            );
        }

        $this->newLine();
        $this->info("Examined {$examined} line(s); {$changed} " . ($apply ? 'updated' : 'would change') . "; {$unresolved} still without a cost in the catalog.");

        if (!$apply && $changed > 0) {
            $this->warn('Nothing was written. Re-run with --apply.');
        }

        return self::SUCCESS;
    }
}
