<?php

namespace App\Console\Commands;

use App\Services\OrderCurrencyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SetOrderCurrencyRate extends Command
{
    protected $signature = 'orders:currency-set-rate
                            {order_id : The order to fix}
                            {rate : Units of default currency per 1 unit of the order currency}
                            {--execute : Apply the change (default is a preview)}';

    protected $description = 'Assign a lost exchange rate to a foreign-currency order and convert its money columns to the default currency';

    public function handle(OrderCurrencyService $currencies): int
    {
        $pfx = (string) config('catalog.prefix');
        $orderId = (int) $this->argument('order_id');
        $rate = (float) $this->argument('rate');
        $execute = (bool) $this->option('execute');

        if ($rate <= 0) {
            $this->error('Rate must be positive.');
            return self::FAILURE;
        }

        $order = DB::table($pfx.'order')->where('order_id', $orderId)->first();

        if (!$order) {
            $this->error("Order {$orderId} not found.");
            return self::FAILURE;
        }

        $defaultCode = $currencies->defaultCode();

        if (strcasecmp((string) $order->currency_code, $defaultCode) === 0) {
            $this->error("Order {$orderId} is already in the default currency ({$defaultCode}). Nothing to do.");
            return self::FAILURE;
        }

        if ($order->foreign_total !== null) {
            $this->error("Order {$orderId} already has foreign_total set - it has been normalized. Refusing to run twice.");
            return self::FAILURE;
        }

        if (!OrderCurrencyService::isDefaultRate((float) $order->currency_value)) {
            $this->error("Order {$orderId} has currency_value={$order->currency_value}, not 1 - this is not a lost-rate order. It already carries a real exchange rate (legacy convention) and must not be run through this command. Use orders:currency-normalize instead.");
            return self::FAILURE;
        }

        $products = DB::table($pfx.'order_product')
            ->where('order_id', $orderId)
            ->select('order_product_id', 'name', 'quantity', 'price', 'total', 'cost')
            ->get();

        $this->info("Order #{$orderId} - {$order->currency_code} at {$rate} {$defaultCode} per 1 {$order->currency_code}");
        $this->newLine();

        $rows = [];
        foreach ($products as $op) {
            $rows[] = [
                mb_substr((string) $op->name, 0, 30),
                $op->quantity,
                number_format((float) $op->price, 2),
                number_format(OrderCurrencyService::toBase((float) $op->price, $rate), 2),
                number_format((float) $op->total, 2),
                number_format(OrderCurrencyService::toBase((float) $op->total, $rate), 2),
                number_format((float) $op->cost, 2),
                number_format(OrderCurrencyService::toBase((float) $op->cost, $rate), 2),
            ];
        }

        $this->table(
            ['product', 'qty', 'price (fx)', "price ({$defaultCode})", 'total (fx)', "total ({$defaultCode})", 'cost (fx)', "cost ({$defaultCode})"],
            $rows
        );

        $this->line(sprintf(
            'Order total: %s %s  ->  %s %s',
            number_format((float) $order->total, 2),
            $order->currency_code,
            number_format(OrderCurrencyService::toBase((float) $order->total, $rate), 2),
            $defaultCode
        ));

        if (!$execute) {
            $this->newLine();
            $this->warn('PREVIEW - nothing written. Re-run with --execute to apply.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($pfx, $orderId, $order, $products, $rate) {
            foreach ($products as $op) {
                DB::table($pfx.'order_product')
                    ->where('order_product_id', $op->order_product_id)
                    ->update([
                        'foreign_price' => round((float) $op->price, OrderCurrencyService::MONEY_SCALE),
                        'foreign_total' => round((float) $op->total, OrderCurrencyService::MONEY_SCALE),
                        'price'         => OrderCurrencyService::toBase((float) $op->price, $rate),
                        'total'         => OrderCurrencyService::toBase((float) $op->total, $rate),
                        'cost'          => OrderCurrencyService::toBase((float) $op->cost, $rate),
                    ]);
            }

            DB::table($pfx.'order')
                ->where('order_id', $orderId)
                ->update([
                    'foreign_total'  => round((float) $order->total, OrderCurrencyService::MONEY_SCALE),
                    'total'          => OrderCurrencyService::toBase((float) $order->total, $rate),
                    'currency_value' => round($rate, OrderCurrencyService::RATE_SCALE),
                ]);

            DB::table($pfx.'order_total')
                ->where('order_id', $orderId)
                ->get()
                ->each(function ($t) use ($pfx, $rate) {
                    DB::table($pfx.'order_total')
                        ->where('order_total_id', $t->order_total_id)
                        ->update(['value' => OrderCurrencyService::toBase((float) $t->value, $rate)]);
                });
        });

        $this->info("Order #{$orderId} converted. Authoritative columns are now {$defaultCode}.");

        return self::SUCCESS;
    }
}
