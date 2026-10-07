<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StampStoreOnMarketplaceOrders extends Migration
{
    public function up(): void
    {
        $o = config('catalog.prefix') . 'order';
        if (! Schema::hasTable($o)) {
            return;
        }

        foreach ([
            ['shopee', 'shopee_orders', 'shopee_setting_id', 'order_sn', 'shopee_settings'],
            ['lazada', 'lazada_orders', 'lazada_setting_id', 'order_id', 'lazada_settings'],
            ['tiktok', 'tiktok_orders', 'tiktok_setting_id', 'order_id', 'tiktok_settings'],
        ] as [$source, $table, $key, $ref, $settings]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $key) || ! Schema::hasTable($settings)) {
                continue;
            }
            DB::statement("UPDATE `$o` o
                JOIN `$table` c ON c.catalog_order_id = o.order_id
                JOIN `$settings` s ON s.id = c.`$key`
                SET o.store_id = s.id, o.store_name = LEFT(COALESCE(s.store_name, ''), 64)
                WHERE o.marketplace_source = ? AND o.store_id = 0", [$source]);
            DB::statement("UPDATE `$o` o
                JOIN `$table` c ON c.`$ref` = o.marketplace_order_id
                JOIN `$settings` s ON s.id = c.`$key`
                SET o.store_id = s.id, o.store_name = LEFT(COALESCE(s.store_name, ''), 64)
                WHERE o.marketplace_source = ? AND o.store_id = 0", [$source]);
        }
    }

    public function down(): void
    {
    }
}
