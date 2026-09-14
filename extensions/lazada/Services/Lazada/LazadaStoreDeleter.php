<?php

namespace Extensions\lazada\Services\Lazada;

use Extensions\lazada\Models\LazadaSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hard delete of a store (the owner, 2026-09-05): everything the store owns,
 * then the row, in one transaction. No restore. Core orders are not
 * touched: they carry the store's id and name themselves.
 *
 * Lazada's data tables carry the store key without a foreign key, so
 * the tables are named here and StoreDeleteTest sweeps the schema for
 * any table carrying lazada_setting_id that still holds a row.
 */
final class LazadaStoreDeleter
{
    /** Child-first. Rows that hang off a product group go with the group. */
    private const TABLES = [
        'lazada_products',
        'lazada_product_groups',
        'lazada_reverse_orders',
        'lazada_orders',
    ];

    /** @return array<string,int> rows removed per table */
    public function delete(LazadaSetting $store): array
    {
        return DB::transaction(function () use ($store) {
            $removed = [];

            if (Schema::hasTable('lazada_products')) {
                LazadaListingStates::on((int) $store->id)->clearErrors(
                    DB::table('lazada_products')->where('lazada_setting_id', $store->id)->whereNotNull('product_id')
                        ->distinct()->pluck('product_id')->map(fn ($v) => (int) $v)->all()
                );
            }

            // The group pivot has no store column of its own: clear it by
            // the store's groups before the groups go.
            if (Schema::hasTable('lazada_product_group_products')) {
                $groupIds = DB::table('lazada_product_groups')->where('lazada_setting_id', $store->id)->pluck('id');
                $removed['lazada_product_group_products'] = $groupIds->isEmpty() ? 0
                    : DB::table('lazada_product_group_products')->whereIn('lazada_product_group_id', $groupIds)->delete();
            }

            foreach (self::TABLES as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'lazada_setting_id')) {
                    continue;
                }
                $removed[$table] = DB::table($table)->where('lazada_setting_id', $store->id)->delete();
            }

            $removed['scheduled_jobs'] = DB::table('scheduled_jobs')
                ->where('integration', 'lazada')->where('store_id', $store->id)->delete();

            DB::table('lazada_settings')->where('id', $store->id)->delete();

            return $removed;
        });
    }
}
