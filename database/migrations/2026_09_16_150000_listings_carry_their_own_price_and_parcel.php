<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every channel's listing gets its own starting price, which the price rule
 * is added to, and its own parcel where it had none. Blank follows the
 * catalog, as every listing field does. Guarded, so a tier that already has
 * a column is left as it is.
 */
return new class extends Migration
{
    private const TABLES = ['shopee_listings', 'lazada_products', 'tiktok_listings', 'venta_listings', 'woocommerce_listings'];

    /** The parcel columns, in Shopee's own types. */
    private const PARCEL = ['weight', 'package_length', 'package_width', 'package_height'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'price')) {
                    $t->decimal('price', 15, 4)->nullable();
                }
                if (! Schema::hasColumn($table, 'weight')) {
                    $t->decimal('weight', 8, 3)->nullable();
                }
                foreach (['package_length', 'package_width', 'package_height'] as $column) {
                    if (! Schema::hasColumn($table, $column)) {
                        $t->unsignedInteger($column)->nullable();
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
            // Shopee had its parcel before this migration; only its price is new.
            $columns = $table === 'shopee_listings' ? ['price'] : array_merge(['price'], self::PARCEL);
            $columns = array_values(array_filter($columns, fn ($c) => Schema::hasColumn($table, $c)));
            if ($columns !== []) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn($columns));
            }
        }
    }
};
