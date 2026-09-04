<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The import queues die (the owner, 2026-09-04: "lets not even save them").
 * The Import pages went stateless - Fetch reads the marketplace live and
 * nothing about its items is persisted - so every *_unmatched_items
 * table goes. The February shopee/lazada pair shipped to live, which is
 * why this is a DROP migration rather than deleted files; the September
 * five never left this branch and their create migrations are gone, so
 * the hasTable guard is what makes this safe on every tier at once.
 * Ghost rows those deleted migrations left in the migrations table are
 * swept so a rollback never looks for files that no longer exist.
 */
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
        // The queues are gone by decision, not by accident; there is no
        // schema worth resurrecting here.
    }
};
