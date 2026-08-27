<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A variation's OWN additional cost.
 *
 * Until now the additional (inbound freight, mostly) lived on the parent and
 * reached every variation unchanged. The owner, 2026-08-27: it is not the same
 * per variation, so it becomes a column of the grid. The percentage stays
 * on the parent; only the additional moves.
 *
 *   unit cost = cost_amount + (parent cost_percentage / 100 * absolute_price)
 *               + cost_additional (this row's)
 *
 * Backfilled from the parent so no computed unit cost changes on migration.
 * product_option_value already had the column (written with the parent's
 * figure since 2026-08-22); it is now written with the row's own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_option_combinations', function (Blueprint $table) {
            $table->decimal('cost_additional', 15, 4)->default(0)->after('cost_amount');
        });

        $product = config('catalog.prefix') . 'product';
        DB::statement("UPDATE product_option_combinations c INNER JOIN `{$product}` p ON p.product_id = c.product_id SET c.cost_additional = p.cost_additional");
    }

    public function down(): void
    {
        Schema::table('product_option_combinations', function (Blueprint $table) {
            $table->dropColumn('cost_additional');
        });
    }
};
