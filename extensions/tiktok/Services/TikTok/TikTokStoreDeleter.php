<?php

namespace Extensions\TikTok\Services\TikTok;

use Extensions\TikTok\Models\TikTokStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class TikTokStoreDeleter
{
    private const TABLES = [
        'ch_tiktok_listings',
        'ch_tiktok_product_groups',
        'ch_tiktok_returns',
        'ch_tiktok_orders',
        'ch_tiktok_description_images',
    ];

    public function delete(TikTokStore $store): array
    {
        return DB::transaction(function () use ($store) {
            $removed = [];

            if (Schema::hasTable('ch_tiktok_listings') && Schema::hasTable('ch_tiktok_product_group_products')) {
                $productIds = DB::table('ch_tiktok_listings')->where('store_id', $store->id)->pluck('product_id')
                    ->merge(DB::table('ch_tiktok_product_group_products as pv')
                        ->join('ch_tiktok_product_groups as g', 'g.id', '=', 'pv.tiktok_product_group_id')
                        ->where('g.store_id', $store->id)->pluck('pv.product_id'))
                    ->map(fn ($v) => (int) $v)->unique()->values()->all();
                app(TikTokListingStates::class)->forStore($store)->clearErrors($productIds);
            }

            if (Schema::hasTable('ch_tiktok_product_group_products')) {
                $groupIds = DB::table('ch_tiktok_product_groups')->where('store_id', $store->id)->pluck('id');
                $removed['ch_tiktok_product_group_products'] = $groupIds->isEmpty() ? 0
                    : DB::table('ch_tiktok_product_group_products')->whereIn('tiktok_product_group_id', $groupIds)->delete();
            }

            foreach (self::TABLES as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'store_id')) {
                    continue;
                }
                $removed[$table] = DB::table($table)->where('store_id', $store->id)->delete();
            }

            $removed['scheduled_jobs'] = DB::table('scheduled_jobs')
                ->where('extension', 'tiktok')->where('store_id', $store->id)->delete();

            DB::table('ch_tiktok_stores')->where('id', $store->id)->delete();

            return $removed;
        });
    }
}
