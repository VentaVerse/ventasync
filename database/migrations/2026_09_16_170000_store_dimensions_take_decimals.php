<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A store's parcel measurements take decimals, as the catalog's always have.
 *
 * The catalog stores length, width and height as decimals, so 10.5 cm saves
 * there. Every store table stored them as whole numbers, so the same value
 * was refused on the listing side of the comparison panel with the browser's
 * own words: "the two nearest valid values are 10 and 11". One marketplace's
 * documented limit had been written into the storage of all five.
 *
 * Weight was already decimal everywhere, which is why a weight of 0.35 kg
 * always worked and a length of 10.5 cm never did.
 *
 * What a marketplace does with a fraction is the marketplace's business: we
 * keep what the seller typed, send it, and let the store answer. Refusing a
 * value the seller can see their own seller centre accept teaches them that
 * we are the obstacle.
 */
return new class extends Migration
{
    private const TABLES = ['shopee_listings', 'lazada_products', 'tiktok_listings', 'venta_listings', 'woocommerce_listings'];

    private const DIMENSIONS = ['package_length', 'package_width', 'package_height'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table) {
                foreach (self::DIMENSIONS as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        /* Two places is a tenth of a millimetre, past anything
                           a parcel is measured in, and the values already
                           stored are whole numbers that widen without loss. */
                        $t->decimal($column, 10, 2)->nullable()->change();
                    }
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table) {
                foreach (self::DIMENSIONS as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        // Anything typed with a fraction is rounded on the way back.
                        $t->unsignedInteger($column)->nullable()->change();
                    }
                }
            });
        }
    }
};
