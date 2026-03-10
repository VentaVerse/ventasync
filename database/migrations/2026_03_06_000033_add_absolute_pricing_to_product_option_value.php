<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $pfx = (string) config('catalog.prefix');
        $table = $pfx . 'product_option_value';

        Schema::table($table, function (Blueprint $t) {
            $t->decimal('absolute_price', 15, 4)->nullable()->after('price_prefix');
            $t->decimal('absolute_cost', 15, 4)->nullable()->after('cost_additional');
        });

        // Backfill absolute_price from base price + modifier
        DB::statement("
            UPDATE `{$table}` pov
            INNER JOIN `{$pfx}product` p ON p.product_id = pov.product_id
            SET pov.absolute_price = CASE
                WHEN pov.price_prefix = '-' THEN p.price - pov.price
                ELSE p.price + pov.price
            END
        ");

        // Backfill absolute_cost from cost breakdown fields
        DB::statement("
            UPDATE `{$table}` pov
            INNER JOIN `{$pfx}product` p ON p.product_id = pov.product_id
            SET pov.absolute_cost = COALESCE(pov.cost_amount, 0)
                + (COALESCE(pov.cost_percentage, 0) / 100 * p.price)
                + COALESCE(pov.cost_additional, 0)
        ");
    }

    public function down(): void
    {
        $pfx = (string) config('catalog.prefix');

        Schema::table($pfx . 'product_option_value', function (Blueprint $t) {
            $t->dropColumn(['absolute_price', 'absolute_cost']);
        });
    }
};
