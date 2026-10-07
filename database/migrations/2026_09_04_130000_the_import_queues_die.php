<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'shopee_unmatched_items',
        'lazada_unmatched_items',
        'tiktok_unmatched_items',
        'venta_unmatched_items',
        'pedallion_unmatched_items',
        'shopify_unmatched_items',
        'opencart_unmatched_items',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }

        DB::table('migrations')->whereIn('migration', [
            '2026_09_03_120000_create_tiktok_unmatched_items_table',
            '2026_09_04_010000_create_venta_unmatched_items',
            '2026_09_04_020000_create_pedallion_unmatched_items',
            '2026_09_04_030000_create_shopify_and_opencart_unmatched_items',
        ])->delete();
    }

    public function down(): void
    {
    }
};
