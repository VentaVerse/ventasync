<?php

namespace App\Console\Commands;

use App\Support\LineCost;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillVariationCosts extends Command
{
    protected $signature = 'catalog:backfill-variation-costs
                            {--apply : Write the changes. Without it the run only reports}
                            {--overwrite : Give every variation the parent\'s amount and additional, even one that has its own}';

    protected $description = 'Give variations without a cost of their own the parent\'s cost amount and additional, and compose their unit cost';

    public function handle(): int
    {
        $pfx = (string) config('catalog.prefix');
        $apply = (bool) $this->option('apply');
        $overwrite = (bool) $this->option('overwrite');

        if (!$apply) {
            $this->warn('Reporting only. Add --apply to write.');
        }

        $parents = DB::table($pfx . 'product as p')
            ->join($pfx . 'product_description as pd', function ($j) {
                $j->on('pd.product_id', '=', 'p.product_id')
                  ->where('pd.language_id', '=', (int) config('catalog.default_language_id', 1));
            })
            ->whereExists(function ($q) use ($pfx) {
                $q->selectRaw('1')->from($pfx . 'product_option_value as pov')->whereColumn('pov.product_id', 'p.product_id');
            })
            ->orderBy('p.product_id')
            ->get(['p.product_id', 'pd.name', 'p.price', 'p.cost_amount', 'p.cost_percentage', 'p.cost_additional', 'p.cost']);

        $rows = [];
        $valuesTouched = 0;
        $combosTouched = 0;
        $parentsWithoutCost = 0;

        foreach ($parents as $p) {
            $amount = (float) $p->cost_amount;
            $pct = (float) $p->cost_percentage;
            $add = (float) $p->cost_additional;

            if ($amount <= 0 && (float) $p->cost <= 0) {
                $parentsWithoutCost++;
                continue;
            }

            $unit = fn (float $ownAmount, float $ownAdd, float $price) => round($ownAmount + ($pct / 100 * $price) + $ownAdd, 4);

            $values = DB::table($pfx . 'product_option_value')
                ->where('product_id', $p->product_id)
                ->get(['product_option_value_id', 'sku', 'absolute_price', 'cost_amount', 'cost_percentage', 'cost_additional', 'absolute_cost', 'cost']);
            $combos = DB::table('product_option_combinations')
                ->where('product_id', $p->product_id)
                ->get(['id', 'sku', 'absolute_price', 'cost_amount', 'cost_additional', 'absolute_cost']);

            $before = 0;
            $after = 0;
            $n = 0;

            foreach ($values as $v) {
                $hasOwn = LineCost::ownCost($v) !== null;
                $take = $overwrite || !$hasOwn;
                $ownAmount = $take ? $amount : (float) $v->cost_amount;
                $ownAdd = $take ? $add : (float) $v->cost_additional;
                $price = (float) $v->absolute_price > 0 ? (float) $v->absolute_price : (float) $p->price;
                $newAbsolute = ($take || (float) $v->cost_amount > 0)
                    ? $unit($ownAmount, $ownAdd, $price)
                    : (float) $v->absolute_cost;
                $delta = (float) $v->cost;

                $changed = $take
                    || abs($newAbsolute - (float) $v->absolute_cost) > 0.00005
                    || $delta != 0.0;
                if (!$changed) {
                    continue;
                }

                $n++;
                $valuesTouched++;
                $before += (float) $v->absolute_cost;
                $after += $newAbsolute;

                if ($apply) {
                    DB::table($pfx . 'product_option_value')
                        ->where('product_option_value_id', $v->product_option_value_id)
                        ->update([
                            'cost_amount' => $ownAmount,
                            'cost_percentage' => $pct,
                            'cost_additional' => $ownAdd,
                            'absolute_cost' => $newAbsolute,
                            'cost' => 0,
                            'cost_prefix' => '+',
                        ]);
                }
            }

            foreach ($combos as $c) {
                $hasOwn = LineCost::ownCombinationCost($c) !== null;
                $take = $overwrite || !$hasOwn;
                $ownAmount = $take ? $amount : (float) $c->cost_amount;
                $ownAdd = $take ? $add : (float) $c->cost_additional;
                $price = (float) $c->absolute_price > 0 ? (float) $c->absolute_price : (float) $p->price;
                $newAbsolute = ($take || (float) $c->cost_amount > 0)
                    ? $unit($ownAmount, $ownAdd, $price)
                    : (float) $c->absolute_cost;

                if (!$take && abs($newAbsolute - (float) $c->absolute_cost) < 0.00005) {
                    continue;
                }

                $combosTouched++;
                if ($apply) {
                    DB::table('product_option_combinations')
                        ->where('id', $c->id)
                        ->update([
                            'cost_amount' => $ownAmount,
                            'cost_additional' => $ownAdd,
                            'absolute_cost' => $newAbsolute,
                            'updated_at' => now(),
                        ]);
                }
            }

            if ($n > 0) {
                $rows[] = [
                    $p->name,
                    $n,
                    number_format($amount, 2),
                    number_format($add, 2),
                    number_format($before / $n, 2),
                    number_format($after / $n, 2),
                ];
            }
        }

        if ($rows !== []) {
            $this->table(['Product', 'Variations', 'Amount', 'Additional', 'Unit cost before (avg)', 'Unit cost after (avg)'], $rows);
        }

        $this->newLine();
        $this->info(sprintf(
            '%d variation value(s) and %d combination(s) across %d product(s) %s; %d product(s) with variations have no cost of their own and were skipped.',
            $valuesTouched,
            $combosTouched,
            count($rows),
            $apply ? 'updated' : 'would change',
            $parentsWithoutCost
        ));

        if (!$apply && ($valuesTouched > 0 || $combosTouched > 0)) {
            $this->warn('Nothing was written. Re-run with --apply, then: php artisan orders:backfill-costs --force --from=<date> --apply');
        }

        return self::SUCCESS;
    }
}
