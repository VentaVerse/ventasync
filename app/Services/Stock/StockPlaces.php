<?php

namespace App\Services\Stock;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where stock is held, as far as a reader outside warehousing needs to
 * know. The catalog's own quantity is what the stores can sell; stock in a
 * warehouse that is not for sale (a supplier's, abroad) is owned but not in
 * that figure, and a reorder has to count it.
 */
final class StockPlaces
{
    /**
     * Units held in warehouses that are not for sale, keyed
     * "{product_id}:{product_option_value_id}" (0 for a product without
     * variations). Empty when there are no warehouses.
     *
     * @param  list<int>  $productIds
     * @return array<string, int>
     */
    public function notForSale(array $productIds): array
    {
        if ($productIds === [] || ! class_exists(\Extensions\warehousing\Services\WarehouseStockService::class)
            || ! Schema::hasTable('warehouse_inventory')) {
            return [];
        }

        return DB::table('warehouse_inventory as wi')
            ->join('warehouses as w', 'w.id', '=', 'wi.warehouse_id')
            ->where('w.is_sellable', 0)
            ->whereIn('wi.product_id', $productIds)
            ->groupBy('wi.product_id', 'wi.product_option_value_id')
            ->selectRaw('wi.product_id, wi.product_option_value_id, SUM(wi.quantity) as units')
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->product_id . ':' . (int) $r->product_option_value_id => (int) $r->units])
            ->all();
    }
}
