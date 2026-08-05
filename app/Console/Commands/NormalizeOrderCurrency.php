<?php

namespace App\Console\Commands;

use App\Services\OrderCurrencyNormalizer as N;
use App\Services\OrderCurrencyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class NormalizeOrderCurrency extends Command
{
    protected $signature = 'orders:currency-normalize
                            {--dry-run : Report what would change without writing (default)}
                            {--execute : Actually apply the changes}';

    protected $description = 'Normalize legacy order currency_value to the PHP-per-foreign convention and populate foreign_* columns';

    public function handle(OrderCurrencyService $currencies): int
    {
        $pfx = (string) config('catalog.prefix');
        $execute = (bool) $this->option('execute');

        if (!$execute) {
            $this->warn('DRY RUN - nothing will be written. Pass --execute to apply.');
        }

        $defaultCode = $currencies->defaultCode();
        $this->info("Default currency: {$defaultCode}");

        // Idempotence guard: classify() only sees (currency_code, currency_value) and
        // cannot tell an already-normalized rate (now > 1) apart from a still-legacy
        // one (a fraction) - both are simply "not 1.0". A row this command has already
        // written foreign_total for must never be fed back into classify(), or a second
        // run would re-invert an already-correct currency_value. This is I/O-layer
        // state, not classification logic, so the guard lives here, not in the pure
        // classifier.
        $orders = DB::table($pfx.'order')
            ->select('order_id', 'currency_code', 'currency_value', 'total')
            ->whereNull('foreign_total')
            ->orderBy('order_id')
            ->get();

        // Each entry: ['order' => $o, 'plan' => the pre-validated conversion
        // plan]. Computed once here and reused by both the report table
        // below and the transaction, instead of recomputing (and
        // re-validating) it twice.
        $legacy = [];
        $lostRate = [];
        $defaults = 0;
        // Rows that classify() puts in the LEGACY bucket (not the default
        // currency, and currency_value isn't ~1) but whose currency_value is
        // otherwise unusable (<= 0 - a corrupt row, not a real rate). These
        // must be skipped and reported, not allowed to abort the entire run.
        $badRows = [];

        foreach ($orders as $o) {
            $class = N::classify((string) $o->currency_code, (float) $o->currency_value, $defaultCode);

            if ($class === N::CLASS_LEGACY) {
                try {
                    $plan = N::planLegacyOrder((float) $o->total, (float) $o->currency_value);
                } catch (\Throwable $e) {
                    $badRows[] = ['order' => $o, 'error' => $e->getMessage()];
                    continue;
                }
                $legacy[] = ['order' => $o, 'plan' => $plan];
            } elseif ($class === N::CLASS_LOST_RATE) {
                $lostRate[] = $o;
            } else {
                $defaults++;
            }
        }

        $this->newLine();
        $this->info('Classification:');
        $this->line("  default-currency orders : {$defaults} (untouched)");
        $this->line('  legacy convention       : '.count($legacy).' (will be normalized)');
        $this->line('  lost rate (cv=1)        : '.count($lostRate).' (NOT touched here)');
        if ($badRows) {
            $this->line('  bad rows (skipped)      : '.count($badRows).' (currency_value <= 0 - needs manual repair)');
        }

        if ($legacy) {
            $this->newLine();
            $this->info('Legacy orders to normalize:');
            $rows = [];
            foreach ($legacy as $entry) {
                $o = $entry['order'];
                $plan = $entry['plan'];
                $rows[] = [
                    $o->order_id,
                    $o->currency_code,
                    $o->currency_value,
                    number_format((float) $o->total, 4),
                    number_format($plan['foreign_total'], 4),
                    $plan['currency_value'],
                ];
            }
            $this->table(
                ['order', 'code', 'old cv', 'total (unchanged)', 'new foreign_total', 'new cv'],
                $rows
            );
        }

        if ($badRows) {
            $this->newLine();
            $this->error('Bad rows - skipped, need manual repair (not normalized by this run):');
            $this->table(
                ['order', 'code', 'currency_value', 'error'],
                array_map(fn ($r) => [
                    $r['order']->order_id,
                    $r['order']->currency_code,
                    $r['order']->currency_value,
                    $r['error'],
                ], $badRows)
            );
        }

        if ($lostRate) {
            $this->newLine();
            $this->warn('Orders with a LOST rate - these need an explicit rate.');
            $this->warn('Run: php artisan orders:currency-set-rate {order_id} {rate}');
            $this->table(
                ['order', 'code', 'total (foreign)', 'date'],
                array_map(fn ($o) => [
                    $o->order_id,
                    $o->currency_code,
                    number_format((float) $o->total, 4),
                    DB::table($pfx.'order')->where('order_id', $o->order_id)->value('date_added'),
                ], $lostRate)
            );
        }

        if (!$execute) {
            $this->newLine();
            $this->warn('DRY RUN complete - nothing was written.');
            return self::SUCCESS;
        }

        if (!$legacy) {
            $this->info($badRows ? 'Nothing to normalize (bad rows above still need manual repair).' : 'Nothing to normalize.');
            return self::SUCCESS;
        }

        $before = [];
        foreach ($legacy as $entry) {
            $before[$entry['order']->order_id] = (string) $entry['order']->total;
        }

        try {
            DB::transaction(function () use ($pfx, $legacy, $before) {
                foreach ($legacy as $entry) {
                    $o = $entry['order'];
                    $orderPlan = $entry['plan'];
                    $oldRate = (float) $o->currency_value;

                    $products = DB::table($pfx.'order_product')
                        ->where('order_id', $o->order_id)
                        ->select('order_product_id', 'price', 'total')
                        ->get();

                    foreach ($products as $op) {
                        $plan = N::planLegacyProduct((float) $op->price, (float) $op->total, $oldRate);

                        DB::table($pfx.'order_product')
                            ->where('order_product_id', $op->order_product_id)
                            ->update([
                                'foreign_price' => $plan['foreign_price'],
                                'foreign_total' => $plan['foreign_total'],
                            ]);
                    }

                    DB::table($pfx.'order')
                        ->where('order_id', $o->order_id)
                        ->update([
                            'foreign_total'  => $orderPlan['foreign_total'],
                            'currency_value' => $orderPlan['currency_value'],
                        ]);
                }

                // Safety assertion: no legacy order's authoritative total may move.
                foreach ($before as $orderId => $originalTotal) {
                    $after = (string) DB::table($pfx.'order')->where('order_id', $orderId)->value('total');

                    if ($after !== $originalTotal) {
                        throw new RuntimeException(
                            "ABORT: order {$orderId} total changed from {$originalTotal} to {$after}. Rolling back."
                        );
                    }
                }
            });
        } catch (\Throwable $e) {
            // Catch Throwable, not just RuntimeException: a corrupt row that
            // slips past the report-block filtering above (or any other
            // unexpected failure inside the transaction) must still roll
            // back cleanly and report a clean message, not surface a raw
            // stack trace while leaving the operator unsure whether anything
            // was written (DB::transaction already rolled back before this
            // catch runs).
            $this->error($e->getMessage());
            $this->error('Transaction rolled back. No changes were applied.');
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Normalized '.count($legacy).' legacy orders. All authoritative totals verified unchanged.');

        if ($lostRate) {
            $this->warn(count($lostRate).' lost-rate order(s) still need an explicit rate.');
        }

        if ($badRows) {
            $this->warn(count($badRows).' bad row(s) skipped - see the table above, needs manual repair.');
        }

        return self::SUCCESS;
    }
}
