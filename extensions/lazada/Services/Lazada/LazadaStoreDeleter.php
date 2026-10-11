<?php

namespace Extensions\Lazada\Services\Lazada;

use Extensions\Lazada\Models\LazadaStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class LazadaStoreDeleter
{
    private const TABLES = [
        'ch_lazada_listings',
        'ch_lazada_product_groups',
        'ch_lazada_reverse_orders',
        'ch_lazada_orders',
    ];

    public function delete(LazadaStore $store): array
    {
        return DB::transaction(function () use ($store) {
            $removed = [];

            if (Schema::hasTable('ch_lazada_listings')) {
                LazadaListingStates::on((int) $store->id)->clearErrors(
                    DB::table('ch_lazada_listings')->where('store_id', $store->id)->whereNotNull('product_id')
                        ->distinct()->pluck('product_id')->map(fn ($v) => (int) $v)->all()
                );
            }

            if (Schema::hasTable('ch_lazada_product_group_products')) {
                $groupIds = DB::table('ch_lazada_product_groups')->where('store_id', $store->id)->pluck('id');
                $removed['ch_lazada_product_group_products'] = $groupIds->isEmpty() ? 0
                    : DB::table('ch_lazada_product_group_products')->whereIn('lazada_product_group_id', $groupIds)->delete();
            }

            foreach (self::TABLES as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'store_id')) {
                    continue;
                }
                $removed[$table] = DB::table($table)->where('store_id', $store->id)->delete();
            }

            $removed['scheduled_jobs'] = DB::table('scheduled_jobs')
                ->where('extension', 'lazada')->where('store_id', $store->id)->delete();

            DB::table('ch_lazada_stores')->where('id', $store->id)->delete();

            return $removed;
        });
    }
}
