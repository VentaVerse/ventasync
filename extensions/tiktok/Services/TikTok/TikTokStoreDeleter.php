<?php

namespace Extensions\tiktok\Services\TikTok;

use Extensions\tiktok\Models\TikTokSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class TikTokStoreDeleter
{
    private const TABLES = [
        'tiktok_listings',
        'tiktok_product_groups',
        'tiktok_returns',
        'tiktok_orders',
        'tiktok_description_images',
    ];

    public function delete(TikTokSetting $store): array
    {
        return DB::transaction(function () use ($store) {
            $removed = [];

            if (Schema::hasTable('tiktok_listings') && Schema::hasTable('tiktok_product_group_products')) {
                $productIds = DB::table('tiktok_listings')->where('tiktok_setting_id', $store->id)->pluck('product_id')
                    ->merge(DB::table('tiktok_product_group_products as pv')
                        ->join('tiktok_product_groups as g', 'g.id', '=', 'pv.tiktok_product_group_id')
                        ->where('g.tiktok_setting_id', $store->id)->pluck('pv.product_id'))
                    ->map(fn ($v) => (int) $v)->unique()->values()->all();
                app(TikTokListingStates::class)->forStore($store)->clearErrors($productIds);
            }

            if (Schema::hasTable('tiktok_product_group_products')) {
                $groupIds = DB::table('tiktok_product_groups')->where('tiktok_setting_id', $store->id)->pluck('id');
                $removed['tiktok_product_group_products'] = $groupIds->isEmpty() ? 0
                    : DB::table('tiktok_product_group_products')->whereIn('tiktok_product_group_id', $groupIds)->delete();
            }

            foreach (self::TABLES as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'tiktok_setting_id')) {
                    continue;
                }
                $removed[$table] = DB::table($table)->where('tiktok_setting_id', $store->id)->delete();
            }

            $removed['scheduled_jobs'] = DB::table('scheduled_jobs')
                ->where('integration', 'tiktok')->where('store_id', $store->id)->delete();

            DB::table('tiktok_settings')->where('id', $store->id)->delete();

            return $removed;
        });
    }
}
