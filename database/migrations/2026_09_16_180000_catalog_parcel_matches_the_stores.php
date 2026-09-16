<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The catalog's parcel is stored the way every store stores it.
 *
 * The catalog kept weight and the three dimensions as decimal(15,8), eight
 * places, which is OpenCart's shape rather than a decision anyone made here:
 * a hundred-millionth of a centimetre is not a parcel measurement. The store
 * tables hold weight to three places (grams) and dimensions to two (a tenth
 * of a millimetre), which is what the marketplaces actually take.
 *
 * Matching them means a value typed in the catalog survives the copy to a
 * listing unchanged, so nobody has to explain why 10.00499 became 10.00 on
 * one page and not another.
 */
return new class extends Migration
{
    public function up(): void
    {
        $pfx = (string) config('catalog.prefix');
        $product = $pfx . 'product';
        if (Schema::hasTable($product)) {
            Schema::table($product, function (Blueprint $t) use ($product) {
                if (Schema::hasColumn($product, 'weight')) {
                    // Grams, as every store table already holds it.
                    $t->decimal('weight', 8, 3)->default(0)->change();
                }
                foreach (['length', 'width', 'height'] as $column) {
                    if (Schema::hasColumn($product, $column)) {
                        $t->decimal($column, 10, 2)->default(0)->change();
                    }
                }
            });
        }

        /* A variation carries its own weight, and it travels the same road to
           the same marketplaces, so it is stored the same way. */
        $optionValue = $pfx . 'product_option_value';
        if (Schema::hasTable($optionValue) && Schema::hasColumn($optionValue, 'weight')) {
            Schema::table($optionValue, fn (Blueprint $t) => $t->decimal('weight', 8, 3)->default(0)->change());
        }
    }

    public function down(): void
    {
        $pfx = (string) config('catalog.prefix');
        $product = $pfx . 'product';
        if (Schema::hasTable($product)) {
            Schema::table($product, function (Blueprint $t) use ($product) {
                foreach (['weight', 'length', 'width', 'height'] as $column) {
                    if (Schema::hasColumn($product, $column)) {
                        $t->decimal($column, 15, 8)->default(0)->change();
                    }
                }
            });
        }

        $optionValue = $pfx . 'product_option_value';
        if (Schema::hasTable($optionValue) && Schema::hasColumn($optionValue, 'weight')) {
            Schema::table($optionValue, fn (Blueprint $t) => $t->decimal('weight', 15, 8)->default(0)->change());
        }
    }
};
