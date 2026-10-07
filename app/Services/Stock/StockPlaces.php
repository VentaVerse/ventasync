<?php

namespace App\Services\Stock;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class StockPlaces
{
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
