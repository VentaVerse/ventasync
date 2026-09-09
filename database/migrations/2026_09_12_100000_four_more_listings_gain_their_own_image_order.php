<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The other four first-tier listings gain the column Shopee took on
 * 2026-09-08, so the arrangeable "Images that go up" card is the same card
 * on every channel (VENT2-131: "all five screens are the same screen").
 *
 * Same meaning as Shopee's, because it is the same card and the same
 * reader: empty is what an empty Content field is, this listing follows
 * the catalog product; a filled column is the listing's own list of
 * storage paths, in the order it goes up.
 *
 * Lazada's listing row is lazada_products, which is why the table name
 * does not match the pattern the other three keep.
 */
return new class extends Migration
{
    /** table => the column the new one sits after. */
    private const TABLES = [
        'lazada_products' => 'description',
        'tiktok_listings' => 'attribute_values',
        'venta_listings' => 'description',
        'woocommerce_listings' => 'description',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $after) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table, $after) {
                if (! Schema::hasColumn($table, 'image_order')) {
                    $t->json('image_order')->nullable()->after($after);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (Schema::hasColumn($table, 'image_order')) {
                    $t->dropColumn('image_order');
                }
            });
        }
    }
};
