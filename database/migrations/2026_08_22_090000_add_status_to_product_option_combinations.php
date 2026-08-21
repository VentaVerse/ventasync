<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a variation is offered for sale.
 *
 * A product could be turned off as a whole and nothing smaller. In practice a
 * size sells out permanently, a colour is discontinued, or one combination is
 * withdrawn from the marketplaces while the rest of the product keeps
 * selling - and the only ways to express that were deleting the variation,
 * which destroys its SKU and its history, or setting stock to zero, which
 * says "temporarily out" rather than "not sold any more".
 *
 * ON THE COMBINATION, for the same reason its image and its price are: the
 * combination is the sellable unit and maps one-to-one onto a marketplace
 * variant. On a Size x Colour product, withdrawing Small/Red must not
 * withdraw Small/Blue.
 *
 * DEFAULT 1, and existing rows are enabled. Every variation in the database
 * today is on sale, so the migration must not change what any of them mean.
 *
 * DELIBERATELY NOT WIRED TO STOCK. product.quantity stays the algebraic sum
 * of its variations, including disabled ones: on-hand is a physical fact
 * about a shelf, and a discontinued variation whose units are still in the
 * building is still stock somebody has to account for. Disabling changes
 * whether it is OFFERED, not whether it EXISTS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_option_combinations', function (Blueprint $table) {
            $table->boolean('status')->default(1)->after('sku');
        });
    }

    public function down(): void
    {
        Schema::table('product_option_combinations', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
