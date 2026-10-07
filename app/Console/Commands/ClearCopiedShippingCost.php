<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ClearCopiedShippingCost extends Command
{
    protected $signature = 'orders:clear-copied-shipping-cost
        {--apply : Clear the values; without it the command only reports what it would change}';

    protected $description = "Clear VentaCart and WooCommerce shipping costs that were copied from the buyer's shipping charge";

    public function handle(): int
    {
        $pfx = (string) config('catalog.prefix');

        $orderIds = DB::table($pfx . 'order as o')
            ->join($pfx . 'order_total as ot', function ($join) {
                $join->on('ot.order_id', '=', 'o.order_id')->where('ot.code', '=', 'shipping');
            })
            ->where(function ($query) {
                $query->where('o.marketplace_source', 'like', 'ventacart:%')
                    ->orWhere('o.marketplace_source', 'like', 'woocommerce:%');
            })
            ->where('o.shipping_cost', '>', 0)
            ->whereRaw('ABS(o.shipping_cost - ot.value) < 0.005')
            ->distinct()
            ->pluck('o.order_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($orderIds === []) {
            $this->info('No orders carry a copied shipping charge.');

            return self::SUCCESS;
        }

        if (! $this->option('apply')) {
            $this->info(count($orderIds) . ' orders carry the buyer\'s shipping charge as their shipping cost. Run with --apply to clear them.');

            return self::SUCCESS;
        }

        foreach (array_chunk($orderIds, 500) as $chunk) {
            DB::table($pfx . 'order')->whereIn('order_id', $chunk)->update(['shipping_cost' => 0]);
        }
        $this->info('Cleared the shipping cost on ' . count($orderIds) . ' orders.');

        return self::SUCCESS;
    }
}
