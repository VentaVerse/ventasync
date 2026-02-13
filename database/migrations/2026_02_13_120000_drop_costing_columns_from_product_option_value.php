<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('catalog.prefix', '');
        $table = $prefix . 'product_option_value';

        if (!Schema::hasTable($table)) {
            return;
        }

        $drop = [];
        foreach (['costing_method', 'costing_amount'] as $col) {
            if (Schema::hasColumn($table, $col)) {
                $drop[] = $col;
            }
        }

        if (empty($drop)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($drop) {
            $blueprint->dropColumn($drop);
        });
    }

    public function down(): void
    {
        // Intentionally left blank. These columns were non-standard and should not be recreated automatically.
    }
};
