<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A variation's own cost AMOUNT, so the product's cost formula reaches it.
 *
 * The parent composes its cost from three parts - a fixed amount, a share of
 * the selling price, and an additional - and prints the total. A variation
 * had one flat number and no formula at all, so a product with a 2%
 * commission and 220 of inbound shipping applied neither to any of its
 * variations, which are the units actually bought and sold.
 *
 * ONLY THE AMOUNT VARIES. A commission percentage and inbound shipping are
 * properties of how you buy and sell, not of the size on the label, so they
 * stay on the parent and apply to every variation - computed against that
 * variation's OWN price, which is the part that makes the percentage
 * meaningful per row. Decided with the owner 2026-08-22.
 *
 *   unit cost = cost_amount + (parent cost_percentage / 100 * absolute_price)
 *               + parent cost_additional
 *
 * BACKFILLED FROM absolute_cost, AND absolute_cost IS LEFT ALONE.
 *
 * The number sitting in absolute_cost today was typed into a column labelled
 * only "Cost", with no formula behind it. Whether the operator meant it as a
 * base or as a finished total cannot be known from here, and 43 rows carry
 * one. Treating it as the amount is the reading that loses nothing: no stored
 * total is rewritten by this migration.
 *
 * For the 14 products that carry an additional, the computed unit cost will
 * therefore read higher than the stored one until somebody saves. That is
 * deliberate and it is visible: the grid prints the computed unit cost under
 * the amount on load, so the new figure is on screen before anyone commits to
 * it, rather than moving quietly underneath them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_option_combinations', function (Blueprint $table) {
            $table->decimal('cost_amount', 15, 4)->default(0)->after('absolute_cost');
        });

        DB::statement('UPDATE product_option_combinations SET cost_amount = absolute_cost');
    }

    public function down(): void
    {
        Schema::table('product_option_combinations', function (Blueprint $table) {
            $table->dropColumn('cost_amount');
        });
    }
};
