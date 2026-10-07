<?php

namespace Extensions\lazada\Services\Lazada;

use Extensions\lazada\Models\LazadaSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class LazadaStoreDeleter
{
    private const TABLES = [
        'lazada_products',
        'lazada_product_groups',
        'lazada_reverse_orders',
        'lazada_orders',
    ];

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
