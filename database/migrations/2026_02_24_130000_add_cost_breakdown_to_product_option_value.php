<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = config('catalog.prefix') . 'product_option_value';

        Schema::table($table, function (Blueprint $t) use ($table) {
            if (!Schema::hasColumn($table, 'cost_percentage')) {
                $t->decimal('cost_percentage', 15, 2)->default(0)->after('cost_amount');
            }
            if (!Schema::hasColumn($table, 'cost_additional')) {
                $t->decimal('cost_additional', 15, 4)->default(0)->after('cost_percentage');
            }
        });

        // Backfill: move existing flat cost into cost_amount where breakdown is empty
        DB::table($table)
            ->where('cost', '>', 0)
            ->whereRaw('cost_amount = 0 AND cost_percentage = 0 AND cost_additional = 0')
            ->update(['cost_amount' => DB::raw('cost')]);
    }

    public function down(): void
    {
        $table = config('catalog.prefix') . 'product_option_value';

        Schema::table($table, function (Blueprint $t) use ($table) {
            $cols = [];
            foreach (['cost_percentage', 'cost_additional'] as $col) {
                if (Schema::hasColumn($table, $col)) {
                    $cols[] = $col;
                }
            }
            if ($cols) {
                $t->dropColumn($cols);
            }
        });
    }
};
